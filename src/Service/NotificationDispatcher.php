<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Terminal42\NotificationCenterBundle\BulkyItem\BulkyItemStorage;
use Terminal42\NotificationCenterBundle\BulkyItem\FileItemFactory;
use Terminal42\NotificationCenterBundle\NotificationCenter;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\AsynchronousDeliveryStamp;
use Terminal42\NotificationCenterBundle\Parcel\Stamp\BulkyItemsStamp;
use Terminal42\NotificationCenterBundle\Receipt\ReceiptCollection;

/**
 * Wraps the Notification Center for the three workflow mails (invitation,
 * reminder and result). The admin edits the actual texts/subjects of the
 * referenced notifications in the Contao back end.
 *
 * Token conventions (configure these in the notification messages):
 *   ##email##           recipient address (use it as the gateway "send to")
 *   ##link##            individual, prefilled form link
 *   ##workflow_title##  the workflow title
 *   ##data_<column>##   any imported source column (sanitized name), including the
 *                       stored answer values (e.g. ##data_verzicht##)
 *   ##letterhead_<variable>##  any master/letterhead variable (e.g. ##letterhead_verein##)
 *   ##text_<column>##   the document statement ("Textbaustein") of the answer
 *                       field storing into that column; ##text_all## for all
 *   ##attachment##      the generated PDF (result mail); stored as a bulky item
 *                       and referenced under "Attachments via tokens"
 *
 * The ##data_*## / ##letterhead_*## / ##text_*## tokens are produced by the shared
 * PlaceholderResolver / DocumentBodyComposer so they are identical to the ones
 * used in the PDF.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationCenter $notificationCenter,
        private readonly BulkyItemStorage $bulkyItemStorage,
        private readonly FileItemFactory $fileItemFactory,
        private readonly PlaceholderResolver $placeholderResolver,
        private readonly DocumentBodyComposer $bodyComposer,
        private readonly WorkflowMailContext $mailContext,
        private readonly Connection $connection,
    ) {
    }

    public function sendInvite(WorkflowModel $workflow, EntryModel $entry, string $link): bool
    {
        return $this->send(
            (int) $workflow->ncInvite,
            $this->baseTokens($workflow, $entry, $link),
            $workflow,
            $entry,
            WorkflowMailContext::KIND_INVITE,
        );
    }

    public function sendReminder(WorkflowModel $workflow, EntryModel $entry, string $link): bool
    {
        return $this->send(
            (int) $workflow->ncReminder,
            $this->baseTokens($workflow, $entry, $link),
            $workflow,
            $entry,
            WorkflowMailContext::KIND_REMINDER,
        );
    }

    public function sendResult(WorkflowModel $workflow, EntryModel $entry, string $pdfAbsolutePath): bool
    {
        $tokens = $this->baseTokens($workflow, $entry, '');

        // NC attaches files by voucher, not by path: store the PDF in the
        // (private, auto-pruned) bulky item storage and pass back the voucher,
        // which the "##attachment##" attachment token resolves to.
        $voucher = $this->bulkyItemStorage->store(
            $this->fileItemFactory->createFromLocalPath($pdfAbsolutePath),
        );
        $tokens['attachment'] = $voucher;

        // The voucher must be registered on the parcel's BulkyItemsStamp,
        // otherwise the "##attachment##" attachment token is ignored.
        return $this->send((int) $workflow->ncResult, $tokens, $workflow, $entry, WorkflowMailContext::KIND_RESULT, [$voucher]);
    }

    /**
     * @param array<string, string> $tokens
     * @param array<int, string>    $bulkyVouchers vouchers to attach (result mail)
     */
    private function send(
        int $notificationId,
        array $tokens,
        WorkflowModel $workflow,
        EntryModel $entry,
        string $kind,
        array $bulkyVouchers = [],
    ): bool {
        if ($notificationId <= 0) {
            return false;
        }

        // Record which entry/kind is being sent. Used by WorkflowMailResultListener as a
        // fallback for synchronous transports, where the receipt event fires within this
        // call (before the parcel id is persisted below).
        $this->mailContext->set((int) $workflow->id, (int) $entry->id, $kind);

        try {
            if ([] === $bulkyVouchers) {
                $receipts = $this->notificationCenter->sendNotification($notificationId, $tokens);
            } else {
                $stamps = $this->notificationCenter
                    ->createBasicStampsForNotification($notificationId, $tokens)
                    ->with(new BulkyItemsStamp($bulkyVouchers))
                ;
                $receipts = $this->notificationCenter->sendNotificationWithStamps($notificationId, $stamps);
            }
        } finally {
            $this->mailContext->clear();
        }

        // Remember the parcel id on the entry so the (asynchronous) send result can be
        // mapped back to it by WorkflowMailResultListener once it is actually delivered.
        $this->rememberParcel($receipts, (int) $entry->id, $kind);

        // NC 2.0 returns a ReceiptCollection: one receipt per message. An empty collection
        // means nothing was handed over. A receipt confirms the mail was accepted by the
        // mailer (which, with asynchronous delivery, means "queued"); the real delivery
        // result updates the entry later via WorkflowMailResultListener.
        return $receipts instanceof ReceiptCollection
            && \count($receipts) > 0
            && $receipts->wereAllDelivered();
    }

    private function rememberParcel(?ReceiptCollection $receipts, int $entryId, string $kind): void
    {
        if (!$receipts instanceof ReceiptCollection) {
            return;
        }

        $identifier = null;

        foreach ($receipts as $receipt) {
            $stamp = $receipt->getParcel()->getStamp(AsynchronousDeliveryStamp::class);

            if ($stamp instanceof AsynchronousDeliveryStamp) {
                $identifier = $stamp->identifier;
            }
        }

        if (null === $identifier) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry SET sendParcelId = ?, sendKind = ? WHERE id = ?',
            [$identifier, $kind, $entryId],
        );
    }

    /**
     * @return array<string, string>
     */
    private function baseTokens(WorkflowModel $workflow, EntryModel $entry, string $link): array
    {
        $data = $entry->getData();
        $vars = $this->masterVars($workflow);
        $email = (string) $entry->email;

        $tokens = $this->placeholderResolver->canonicalTokens(
            $data,
            $vars,
            $email,
            (string) $workflow->title,
        );

        // Same statement tokens as in the PDF, so a result mail can quote the
        // participant's choices verbatim (##text_all## / ##text_<column>##).
        $tokens = [...$tokens, ...$this->bodyComposer->statementTokens($workflow, $data, $vars, $email)];

        $tokens['link'] = $link;

        return $tokens;
    }

    /**
     * @return array<string, string>
     */
    private function masterVars(WorkflowModel $workflow): array
    {
        if (!$workflow->master) {
            return [];
        }

        $master = MasterModel::findByPk((int) $workflow->master);

        return null !== $master ? $master->getPdfData() : [];
    }
}
