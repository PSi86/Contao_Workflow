<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Terminal42\NotificationCenterBundle\BulkyItem\BulkyItemStorage;
use Terminal42\NotificationCenterBundle\BulkyItem\FileItemFactory;
use Terminal42\NotificationCenterBundle\NotificationCenter;
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
 *   ##var_<variable>##  any master/letterhead variable (e.g. ##var_verein##)
 *   ##attachment##      the generated PDF (result mail); stored as a bulky item
 *                       and referenced under "Attachments via tokens"
 *
 * The ##data_*## / ##var_*## tokens are produced by the shared PlaceholderResolver
 * so they are identical to the ones used in the PDF.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationCenter $notificationCenter,
        private readonly BulkyItemStorage $bulkyItemStorage,
        private readonly FileItemFactory $fileItemFactory,
        private readonly PlaceholderResolver $placeholderResolver,
    ) {
    }

    public function sendInvite(WorkflowModel $workflow, EntryModel $entry, string $link): bool
    {
        return $this->send((int) $workflow->ncInvite, $this->baseTokens($workflow, $entry, $link));
    }

    public function sendReminder(WorkflowModel $workflow, EntryModel $entry, string $link): bool
    {
        return $this->send((int) $workflow->ncReminder, $this->baseTokens($workflow, $entry, $link));
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
        return $this->send((int) $workflow->ncResult, $tokens, [$voucher]);
    }

    /**
     * @param array<string, string> $tokens
     * @param array<int, string>    $bulkyVouchers vouchers to attach (result mail)
     */
    private function send(int $notificationId, array $tokens, array $bulkyVouchers = []): bool
    {
        if ($notificationId <= 0) {
            return false;
        }

        if ([] === $bulkyVouchers) {
            $receipts = $this->notificationCenter->sendNotification($notificationId, $tokens);
        } else {
            $stamps = $this->notificationCenter
                ->createBasicStampsForNotification($notificationId, $tokens)
                ->with(new BulkyItemsStamp($bulkyVouchers))
            ;
            $receipts = $this->notificationCenter->sendNotificationWithStamps($notificationId, $stamps);
        }

        // NC 2.0 returns a ReceiptCollection: one receipt per message. An empty
        // collection means nothing was sent; a receipt can also represent a
        // failed delivery, so we require that everything was actually delivered.
        return $receipts instanceof ReceiptCollection
            && \count($receipts) > 0
            && $receipts->wereAllDelivered();
    }

    /**
     * @return array<string, string>
     */
    private function baseTokens(WorkflowModel $workflow, EntryModel $entry, string $link): array
    {
        $tokens = $this->placeholderResolver->canonicalTokens(
            $entry->getData(),
            $this->masterVars($workflow),
            (string) $entry->email,
            (string) $workflow->title,
        );

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
