<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Service;

use Psimandl\TrainerWorkflowBundle\Model\EntryModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;

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

        $entry->status = WorkflowStatus::STATUS_RESPONDED;
        $entry->respondedAt = time();
        $entry->tstamp = time();
        $entry->save();

        $relativePath = $this->pdfGenerator->generateAndStore($entry, $workflow);

        $this->notificationDispatcher->sendResult(
            $workflow,
            $entry,
            $this->pdfStorage->getAbsolutePath($relativePath),
        );
    }

    private function stripDataUriPrefix(string $signature): string
    {
        if (preg_match('#^data:image/[a-z]+;base64,(.*)$#is', $signature, $matches)) {
            return $matches[1];
        }

        return $signature;
    }
}
