<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Service;

use Psimandl\TrainerWorkflowBundle\Model\EntryModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;
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
 *   ##attachment##      the generated PDF (result mail); stored as a bulky item
 *                       and referenced under "Attachments via tokens"
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationCenter $notificationCenter,
        private readonly BulkyItemStorage $bulkyItemStorage,
        private readonly FileItemFactory $fileItemFactory,
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
        $tokens = [
            'email'          => (string) $entry->email,
            'link'           => $link,
            'workflow_title' => (string) $workflow->title,
        ];

        foreach ($entry->getData() as $key => $value) {
            $tokens['data_'.$this->normalizeTokenName((string) $key)] = (string) $value;
        }

        return $tokens;
    }

    private function normalizeTokenName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? '';

        return strtolower(trim($name, '_'));
    }
}
