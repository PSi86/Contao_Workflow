<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Sends invitation and reminder mails for a workflow. Shared by the back end
 * action controller and the CLI command.
 */
class WorkflowMailer
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LinkGenerator $linkGenerator,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {
    }

    /**
     * Sends the invitation to freshly imported entries (status 0) and advances
     * them to "invited" (status 1). With $entryIds only those entries are sent.
     *
     * @param array<int, int>|null $entryIds
     */
    public function sendInvitations(WorkflowModel $workflow, ?array $entryIds = null): int
    {
        return $this->dispatch($workflow, WorkflowStatus::STATUS_IMPORTED, false, $entryIds);
    }

    /**
     * Sends a reminder to invited-but-not-answered entries (status 1). With
     * $entryIds only those entries are sent.
     *
     * @param array<int, int>|null $entryIds
     */
    public function sendReminders(WorkflowModel $workflow, ?array $entryIds = null): int
    {
        return $this->dispatch($workflow, WorkflowStatus::STATUS_INVITED, true, $entryIds);
    }

    /**
     * @param array<int, int>|null $entryIds restrict to these entry ids (manual selection)
     */
    private function dispatch(WorkflowModel $workflow, int $status, bool $isReminder, ?array $entryIds = null): int
    {
        $this->framework->initialize();

        $entries = EntryModel::findByWorkflowAndStatus((int) $workflow->id, $status);
        $sent = 0;

        if (null === $entries) {
            return 0;
        }

        foreach ($entries as $entry) {
            if (null !== $entryIds && !\in_array((int) $entry->id, $entryIds, true)) {
                continue;
            }

            if ('' === (string) $entry->email) {
                continue;
            }

            $link = $this->linkGenerator->getFormLink($workflow, $entry);

            $ok = $isReminder
                ? $this->notificationDispatcher->sendReminder($workflow, $entry, $link)
                : $this->notificationDispatcher->sendInvite($workflow, $entry, $link);

            if (!$ok) {
                continue;
            }

            ++$sent;

            // Advance the status only for the initial invitation.
            if (!$isReminder) {
                $entry->status = WorkflowStatus::STATUS_INVITED;
                $entry->sentAt = time();
                $entry->tstamp = time();
                $entry->save();
            }
        }

        return $sent;
    }
}
