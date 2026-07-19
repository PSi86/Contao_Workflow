<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Input;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\TokenGenerator;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;

/**
 * DCA callbacks for tl_workflow_entry.
 */
class EntryListener
{
    public function __construct(
        private readonly TokenGenerator $tokenGenerator,
        private readonly Connection $connection,
        private readonly WorkflowStatus $workflowStatus,
    ) {
    }

    /**
     * Renders one entry row in the parent (workflow) child view.
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_workflow_entry', target: 'list.sorting.child_record')]
    public function renderChildRecord(array $row): string
    {
        $workflow = WorkflowModel::findByPk((int) ($row['pid'] ?? 0));
        $email = StringUtil::specialchars((string) ($row['email'] ?? ''));

        return sprintf(
            '<div class="tl_content_left">%s <span style="color:#999">[%s]</span></div>',
            $email,
            StringUtil::specialchars($this->stepLabel($workflow, (int) ($row['status'] ?? 0))),
        );
    }

    /**
     * Pretty-prints the serialized data blob as JSON for the read-only field.
     */
    #[AsCallback(table: 'tl_workflow_entry', target: 'fields.data.load')]
    public function formatDataForDisplay(mixed $value): string
    {
        $data = StringUtil::deserialize($value, true);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Clears the hard-bounce suppression when the e-mail address is changed. Without this a
     * corrected address would stay locked out of every future invitation/reminder run.
     */
    #[AsCallback(table: 'tl_workflow_entry', target: 'fields.email.save')]
    public function resetBounceOnEmailChange(mixed $value, DataContainer $dc): mixed
    {
        $id = (int) ($dc->id ?? 0);

        if ($id > 0 && (string) $value !== (string) ($dc->activeRecord->email ?? '')) {
            $this->connection->executeStatement(
                "UPDATE tl_workflow_entry SET bounceHard = '', bounceInfo = '' WHERE id = ?",
                [$id],
            );
        }

        return $value;
    }

    /**
     * The workflow's step labels, keyed by status value. Labels only — the values are the
     * status integers the bundle actually writes (imported / invited / responded).
     *
     * The DCA field needs eval.isAssociative, because these keys form a gapless 0-based
     * array that Contao would otherwise treat as a plain list of values.
     *
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_workflow_entry', target: 'fields.status.options')]
    public function getStatusOptions(?DataContainer $dc = null): array
    {
        $workflow = WorkflowModel::findByPk($this->resolveWorkflowId($dc));
        $options = [];

        for ($i = WorkflowStatus::STATUS_IMPORTED; $i <= WorkflowStatus::STATUS_RESPONDED; ++$i) {
            $options[$i] = $this->stepLabel($workflow, $i);
        }

        return $options;
    }

    /**
     * Reopens an entry whose status was set back below "responded": the stored answers stay
     * (they prefill the form), but the response bookkeeping has to go, otherwise the entry
     * still counts as answered everywhere else — the retry cron and the "re-send confirmation"
     * action would ship the old confirmation again, and the dashboard would keep hiding it.
     *
     * Runs on submit rather than as a save_callback on the field: that way the status has
     * already been written and validated, so a rejected write can no longer leave the
     * bookkeeping cleared behind a status that never changed. It also covers every path that
     * writes the status, not just the one field. Contao calls submit() per record in
     * editAll/overrideAll too, so the mass reset is covered.
     *
     * A single conditional UPDATE: the WHERE clause is the entire decision, which makes this
     * idempotent and lets it heal an entry that is already in that inconsistent state.
     */
    #[AsCallback(table: 'tl_workflow_entry', target: 'config.onsubmit')]
    public function reopenOnStatusReset(DataContainer $dc): void
    {
        $id = (int) ($dc->id ?? 0);

        if ($id < 1) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry '
            ."SET respondedAt = 0, resultDoneAt = 0, resultError = '', tstamp = ? "
            .'WHERE id = ? AND status < ? AND respondedAt > 0',
            [time(), $id, WorkflowStatus::STATUS_RESPONDED],
        );
    }

    /**
     * Ensures a manually created entry always receives a unique token.
     */
    #[AsCallback(table: 'tl_workflow_entry', target: 'config.onsubmit')]
    public function ensureToken(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $current = \Contao\Database::getInstance()
            ->prepare('SELECT token FROM tl_workflow_entry WHERE id=?')
            ->execute($dc->id)
        ;

        if ($current->numRows && '' === (string) $current->token) {
            \Contao\Database::getInstance()
                ->prepare('UPDATE tl_workflow_entry SET token=? WHERE id=?')
                ->execute($this->tokenGenerator->generate(), $dc->id)
            ;
        }
    }

    /**
     * Step label for a status value, falling back to the bare number when the workflow is
     * gone or has no label for that step. Single source for the list view and the select.
     */
    private function stepLabel(?WorkflowModel $workflow, int $status): string
    {
        return null !== $workflow
            ? $this->workflowStatus->getStepLabel($workflow, $status)
            : (string) $status;
    }

    /**
     * Parent workflow of the entry currently being edited. In the mass-edit views the fields
     * are rendered for the whole selection, where "id" is the parent workflow rather than an
     * entry — checking the act first avoids mistaking a workflow id for an entry id.
     */
    private function resolveWorkflowId(?DataContainer $dc): int
    {
        if (isset($dc->activeRecord->pid) && (int) $dc->activeRecord->pid > 0) {
            return (int) $dc->activeRecord->pid;
        }

        if (\in_array((string) Input::get('act'), ['select', 'editAll', 'overrideAll'], true)) {
            return (int) Input::get('id');
        }

        $entry = $dc?->id ? EntryModel::findByPk((int) $dc->id) : null;

        return null !== $entry ? (int) $entry->pid : 0;
    }
}
