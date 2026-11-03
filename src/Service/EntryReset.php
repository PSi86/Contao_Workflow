<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Resets the run state of participants: the whole workflow ("Alle Teilnehmer zurücksetzen"
 * in the edit mask) or a single entry whose e-mail address was changed in the back end.
 *
 * What a run leaves on an entry falls into three groups, and they are treated differently:
 *
 *  - Run state – step, answer time, confirmation state, send time, transient send errors.
 *    A reset takes all of it back to "freshly imported".
 *  - Knowledge about the address – a hard bounce or a permanent rejection by the mail server
 *    (bounceHard/bounceInfo). It describes the address, not the run, so a reset keeps it;
 *    only changing the address lifts it. Otherwise a reset would make the next invitation
 *    run write to an address that is known to be dead.
 *  - The participant's material – answer data, link (token), stored documents. A reset keeps
 *    it: the participant fills in the same prefilled form again through the same link.
 *
 * The send log (tl_workflow_send) is never touched here: it records what was sent, which a
 * reset cannot undo, and it is what correlates a bounce that arrives after the reset.
 */
class EntryReset
{
    /**
     * An entry carries run state as soon as any of these is set. Shared by the count (button
     * label, "nothing to reset" hint) and the reset itself, so both always agree.
     */
    private const HAS_RUN_STATE = 'status <> 0 OR respondedAt > 0 OR resultDoneAt > 0 OR resultError <> \'\' '
        ."OR sentAt > 0 OR (sendError IS NOT NULL AND sendError <> '') OR sendErrorAt > 0";

    public function __construct(
        private readonly Connection $connection,
        private readonly TokenGenerator $tokenGenerator,
    ) {
    }

    /**
     * Number of entries of the workflow a reset would change.
     */
    public function countResettable(int $workflowId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_workflow_entry WHERE pid = ? AND ('.self::HAS_RUN_STATE.')',
            [$workflowId],
        );
    }

    /**
     * Takes every entry of the workflow back to "imported" and clears its run state. Returns
     * the number of entries that changed.
     */
    public function resetWorkflow(int $workflowId): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE tl_workflow_entry '
            .'SET status = ?, respondedAt = 0, resultDoneAt = 0, resultError = \'\', '
            ."sentAt = 0, sendError = '', sendErrorAt = 0, tstamp = ? "
            .'WHERE pid = ? AND ('.self::HAS_RUN_STATE.')',
            [WorkflowStatus::STATUS_IMPORTED, time(), $workflowId],
        );
    }

    /**
     * Applies the consequences of a changed e-mail address. Call it after the new address has
     * been stored.
     *
     * Always: the block and the send error belonged to the old address and are cleared.
     *
     * Not answered yet: the entry starts over – back to "imported", so the next invitation run
     * writes to the new address (a reminder would tell somebody "you have not answered" who
     * never received the invitation), and a new link. The old invitation may have reached a
     * wrong but existing address, and its link opens a form prefilled with personal data.
     *
     * Already answered: the answer stands – a corrected address does not void it – and the link
     * stays. If the confirmation did not get through to the old address (blocked or failed),
     * it is marked outstanding again: the retry cron sends it to the new address, or the admin
     * does via "Bestätigung senden".
     */
    public function applyAddressChange(int $entryId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT respondedAt, bounceHard, sendError FROM tl_workflow_entry WHERE id = ?',
            [$entryId],
        );

        if (false === $row) {
            return;
        }

        $now = time();

        if ((int) $row['respondedAt'] <= 0) {
            $this->connection->executeStatement(
                'UPDATE tl_workflow_entry SET status = ?, sentAt = 0, token = ?, '
                ."bounceHard = '', bounceInfo = '', sendError = '', sendErrorAt = 0, tstamp = ? WHERE id = ?",
                [WorkflowStatus::STATUS_IMPORTED, $this->tokenGenerator->generate(), $now, $entryId],
            );

            return;
        }

        $confirmationFailed = '1' === (string) $row['bounceHard'] || '' !== trim((string) $row['sendError']);

        $this->connection->executeStatement(
            "UPDATE tl_workflow_entry SET bounceHard = '', bounceInfo = '', sendError = '', sendErrorAt = 0, "
            .($confirmationFailed ? "resultDoneAt = 0, resultError = '', " : '')
            .'tstamp = ? WHERE id = ?',
            [$now, $entryId],
        );
    }
}
