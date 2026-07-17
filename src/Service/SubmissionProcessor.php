<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Processes a submitted form: persists the answers, advances the status to
 * "responded", generates the PDF and sends the result mail with the PDF.
 */
class SubmissionProcessor
{
    public function __construct(
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfStorage $pdfStorage,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {
    }

    /**
     * @param array<string, string> $answers source column => submitted value
     * @param string|null           $signature signature data URI, or null when none was provided
     */
    public function process(WorkflowModel $workflow, EntryModel $entry, array $answers, ?string $signature): void
    {
        // Write the configured answer values back into the entry data so they
        // appear in the export and are available as PDF tokens.
        if ([] !== $answers) {
            $entry->data = serialize(array_merge($entry->getData(), $answers));
        }

        if (null !== $signature) {
            $entry->signature = $this->stripDataUriPrefix($signature);
        }

        // The response is a fact the moment the participant submits valid data — persist it
        // first and unconditionally. It must never be lost, no matter what happens next.
        $entry->status = WorkflowStatus::STATUS_RESPONDED;
        $entry->respondedAt = time();
        $entry->tstamp = time();
        $entry->save();

        // Producing the confirmation (PDF + result mail) is a separate, retryable step. A
        // failure here must not lose the response, must not surface as an error to the
        // participant, and must not leave a silent inconsistent state — see
        // produceConfirmation(). The retry cron and the back end action re-run exactly this.
        $this->produceConfirmation($workflow, $entry);
    }

    /**
     * Generates the PDF and sends the result mail, recording success/failure on the entry.
     * Never throws. Returns true when the confirmation was produced. Idempotent: the PDF is
     * overwritten and a repeated result mail is harmless, so it is safe to call again from
     * the retry cron or the "re-send confirmation" back end action.
     */
    public function produceConfirmation(WorkflowModel $workflow, EntryModel $entry): bool
    {
        try {
            $relativePath = $this->pdfGenerator->generateAndStore($entry, $workflow);

            $this->notificationDispatcher->sendResult(
                $workflow,
                $entry,
                $this->pdfStorage->getAbsolutePath($relativePath),
            );

            $entry->resultDoneAt = time();
            $entry->resultError = '';
            $entry->tstamp = time();
            $entry->save();

            return true;
        } catch (\Throwable $e) {
            $entry->resultDoneAt = 0;
            $entry->resultError = $this->shorten($e->getMessage());
            $entry->tstamp = time();
            $entry->save();

            return false;
        }
    }

    private function shorten(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message) > 240 ? mb_substr($message, 0, 240).'…' : $message;
    }

    private function stripDataUriPrefix(string $signature): string
    {
        if (preg_match('#^data:image/[a-z]+;base64,(.*)$#is', $signature, $matches)) {
            return $matches[1];
        }

        return $signature;
    }
}
