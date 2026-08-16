<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Psimandl\WorkflowBundle\Excel\ColumnCompatibility;
use Psimandl\WorkflowBundle\Excel\ColumnFormatAnalyzer;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\RuleModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;

/**
 * Presentational, option and validation callbacks for the answer-field
 * (tl_workflow_question) and PDF-rule (tl_workflow_rule) child tables.
 *
 * Must stay free of constructor dependencies: the MultiColumnWizard and the
 * dcaWizard callbacks resolve this class via System::importStatic(), which
 * instantiates it without container injection.
 */
class AnswerConfigListener
{
    /**
     * Renders one answer-field row in the workflow's child view.
     *
     * @param array<string, mixed> $row
     */
    public function renderQuestionRecord(array $row): string
    {
        $label = StringUtil::specialchars((string) ($row['label'] ?? ''));
        $type = (string) ($row['type'] ?? '');
        $typeLabel = $GLOBALS['TL_LANG']['tl_workflow_question']['typeOptions'][$type] ?? $type;
        $storage = StringUtil::specialchars((string) ($row['storageField'] ?? ''));

        // Conditional fields state their condition here as well; this view has no marker
        // column, so the plain sentence (with column names, no sibling labels) has to do.
        $mode = (string) ($row['conditionMode'] ?? '');
        $conditions = \in_array($mode, ['show', 'hide'], true) ? $this->extractConditions($row['conditions'] ?? null) : [];
        $condition = '';

        if ([] !== $conditions) {
            $condition = ' <span style="color:#999">&#x21B3; '.StringUtil::specialchars($this->formatQuestionCondition([
                'label'      => (string) ($row['label'] ?? ''),
                'mode'       => $mode,
                'logic'      => 'or' === (string) ($row['conditionLogic'] ?? 'and') ? 'or' : 'and',
                'conditions' => $conditions,
            ], [])).'</span>';
        }

        return sprintf(
            '<div class="tl_content_left"><strong>%s</strong> <span style="color:#999">[%s &rarr; %s]</span>%s</div>',
            $label,
            StringUtil::specialchars((string) $typeLabel),
            $storage,
            $condition,
        );
    }

    /**
     * Renders the embedded answer-field list (dcaWizard list_callback) with a
     * drag handle per row: the order is changed directly in this list (HTML5
     * drag&drop, see public/workflow-question-sort.js). The new order is held in
     * a hidden form field and only written when the workflow is saved
     * (AnswerConfigListener::persistQuestionOrder) – nothing is persisted before
     * the user clicks "save".
     *
     * @param array<int, array<string, mixed>> $records
     */
    public function renderQuestionsList(array $records, string $id, object $widget): string
    {
        // Hide abandoned "new" records (act=create inserts a tstamp=0 row at once;
        // see cleanupAbandonedChildren for the DB cleanup).
        $records = array_values(array_filter($records, static fn (array $r): bool => (int) ($r['tstamp'] ?? 0) > 0));

        if ([] === $records) {
            return '<p>'.StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow']['questionsEmpty'] ?? '')).'</p>';
        }

        $GLOBALS['TL_CSS']['wf_backend'] = 'bundles/contaoworkflow/workflow-backend.css';
        $GLOBALS['TL_JAVASCRIPT']['wf_qsort'] = 'bundles/contaoworkflow/workflow-question-sort.js|static';
        $GLOBALS['TL_JAVASCRIPT']['wf_qdeps'] = 'bundles/contaoworkflow/workflow-question-deps.js|static';

        $lang = $GLOBALS['TL_LANG']['tl_workflow_question'] ?? [];
        $hLabel = $lang['label'][0] ?? 'Überschrift';
        $hType = $lang['type'][0] ?? 'Typ';
        $hStorage = $lang['storageField'][0] ?? 'Speicherfeld';
        $hMandatory = $lang['mandatory'][0] ?? 'Pflichtfeld';
        $typeLabels = $lang['typeOptions'] ?? [];

        $dependencies = $this->dependencyMarkers($records);

        $body = '';

        foreach ($records as $row) {
            $type = (string) ($row['type'] ?? '');
            $typeLabel = (string) ($typeLabels[$type] ?? $type);

            $flags = [];

            if ('1' === (string) ($row['readOnly'] ?? '')) {
                $flags[] = $lang['readOnly'][0] ?? 'Schreibgeschützt';
            }

            if ('1' === (string) ($row['prefill'] ?? '')) {
                $flags[] = 'vorbelegt';
            }

            $typeText = StringUtil::specialchars($typeLabel)
                .([] !== $flags ? ' <span style="color:#999">('.StringUtil::specialchars(implode(', ', $flags)).')</span>' : '');

            $operations = $widget->generateRowOperation('edit', $row).$widget->generateRowOperation('delete', $row);
            $marker = $dependencies[(int) $row['id']] ?? ['cell' => '', 'attributes' => ''];

            $body .= '<tr class="hover-row" data-question-id="'.(int) $row['id'].'"'.$marker['attributes'].'>'
                .'<td class="tl_file_list tw-drag-handle" draggable="true" title="'
                .StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow']['questionsDrag'] ?? 'Ziehen, um die Reihenfolge zu ändern')).'">&#x2630;</td>'
                .'<td class="tl_file_list tw-dep-cell">'.$marker['cell'].'</td>'
                .'<td class="tl_file_list"><strong>'.StringUtil::specialchars((string) ($row['label'] ?? '')).'</strong></td>'
                .'<td class="tl_file_list">'.$typeText.'</td>'
                .'<td class="tl_file_list">'.StringUtil::specialchars((string) ($row['storageField'] ?? '')).'</td>'
                .'<td class="tl_file_list">'.('1' === (string) ($row['mandatory'] ?? '') ? '&#x2713;' : '&ndash;').'</td>'
                .'<td class="tl_file_list tl_right_nowrap">'.$operations.'</td>'
                .'</tr>';
        }

        return '<div data-question-sort data-wf-order-error="'
            .StringUtil::specialchars((string) ($lang['depOrderError'] ?? 'Dieses Feld steht vor seinem Auslösefeld.')).'">'
            .'<table class="tl_listing showColumns"><thead><tr>'
            .'<th class="tl_folder_tlist"></th>'
            .'<th class="tl_folder_tlist" title="'.StringUtil::specialchars((string) ($lang['depColumn'] ?? 'Abhängigkeit')).'"></th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hLabel).'</th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hType).'</th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hStorage).'</th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hMandatory).'</th>'
            .'<th class="tl_folder_tlist"></th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }

    /**
     * Dependency markers for the answer-field list: a numbered badge on every field that other
     * fields depend on, and an arrow with those numbers on the fields that depend on it. The
     * readable condition ("anzeigen, wenn „Haben Sie Kinder?" ist gleich ja") rides along as
     * the badge's tooltip, in both directions.
     *
     * Deliberately markers instead of indentation: the list order is free (a dependent field
     * need not follow its trigger directly) and a field may have several triggers – neither
     * fits a tree, and a server-rendered indentation would be stale the moment a row is
     * dragged. The numbers are assigned here, in list order, and are NOT renumbered by the
     * browser after a drop; only the order check runs again (workflow-question-deps.js).
     *
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<int, array{cell: string, attributes: string}>
     */
    private function dependencyMarkers(array $records): array
    {
        $rows = [];
        $labelByColumn = [];
        $referenced = [];

        foreach ($records as $record) {
            $column = trim((string) ($record['storageField'] ?? ''));
            $mode = (string) ($record['conditionMode'] ?? '');
            $conditions = \in_array($mode, ['show', 'hide'], true) ? $this->extractConditions($record['conditions'] ?? null) : [];

            $rows[] = [
                'id'         => (int) ($record['id'] ?? 0),
                'label'      => trim((string) ($record['label'] ?? '')),
                'column'     => $column,
                'mode'       => $mode,
                'logic'      => 'or' === (string) ($record['conditionLogic'] ?? 'and') ? 'or' : 'and',
                'conditions' => $conditions,
            ];

            if ('' !== $column && !isset($labelByColumn[$column])) {
                $labelByColumn[$column] = trim((string) ($record['label'] ?? '')) ?: $column;
            }

            foreach ($conditions as $condition) {
                $referenced[$condition['field']][] = trim((string) ($record['label'] ?? '')) ?: $column;
            }
        }

        // Number the trigger columns in list order.
        $numbers = [];

        foreach ($rows as $row) {
            if ('' !== $row['column'] && isset($referenced[$row['column']]) && !isset($numbers[$row['column']])) {
                $numbers[$row['column']] = \count($numbers) + 1;
            }
        }

        $lang = $GLOBALS['TL_LANG']['tl_workflow_question'] ?? [];
        $markers = [];

        foreach ($rows as $row) {
            $badges = [];
            $columns = [];

            if ('' !== $row['column'] && isset($numbers[$row['column']])) {
                $badges[] = '<span class="tw-dep tw-dep-trigger" title="'
                    .StringUtil::specialchars(sprintf(
                        (string) ($lang['depTrigger'] ?? 'Steuert: %s'),
                        implode(', ', array_map(static fn (string $l): string => '„'.$l.'“', $referenced[$row['column']])),
                    )).'">'.$this->circledNumber($numbers[$row['column']]).'</span>';
            }

            if ([] !== $row['conditions']) {
                $refs = '';

                foreach ($row['conditions'] as $condition) {
                    $columns[$condition['field']] = true;
                    // A condition on a column no field writes cannot be numbered – it is one
                    // of the states WorkflowValidator reports, and "!" is how it looks here.
                    $refs .= isset($numbers[$condition['field']]) ? $this->circledNumber($numbers[$condition['field']]) : '!';
                }

                $badges[] = '<span class="tw-dep tw-dep-child" title="'
                    .StringUtil::specialchars($this->formatQuestionCondition($row, $labelByColumn)).'">&#x21B3;'.$refs.'</span>';
            }

            $markers[$row['id']] = [
                'cell'       => implode(' ', $badges),
                'attributes' => ('' !== $row['column'] ? ' data-wf-col="'.StringUtil::specialchars($row['column']).'"' : '')
                    .([] !== $columns ? ' data-wf-depends="'.StringUtil::specialchars(implode(',', array_keys($columns))).'"' : ''),
            ];
        }

        return $markers;
    }

    /**
     * One field's visibility condition as a readable sentence, e.g.
     * «anzeigen, wenn „Haben Sie Kinder?" ist gleich ja». The tooltip of the list marker.
     *
     * @param array{label: string, mode: string, logic: string, conditions: array<int, array{field: string, operator: string, value: string}>} $row
     * @param array<string, string> $labelByColumn
     */
    private function formatQuestionCondition(array $row, array $labelByColumn): string
    {
        System::loadLanguageFile('tl_workflow_rule');

        $lang = $GLOBALS['TL_LANG']['tl_workflow_question'] ?? [];
        $operators = $GLOBALS['TL_LANG']['tl_workflow_rule']['operatorOptions'] ?? [];
        $parts = [];

        foreach ($row['conditions'] as $condition) {
            $field = $labelByColumn[$condition['field']] ?? $condition['field'];
            // The operator labels carry their symbol in brackets ("ist gleich (=)"), which is
            // helpful in the dropdown and noise inside a sentence.
            $operator = (string) preg_replace(
                '/\s*\([^)]*\)$/u',
                '',
                (string) ($operators[$condition['operator']] ?? $condition['operator']),
            );
            $value = \in_array($condition['operator'], ['empty', 'notempty'], true) ? '' : ' '.$condition['value'];

            $parts[] = trim('„'.$field.'“ '.$operator.$value);
        }

        $glue = ' '.(string) ('or' === $row['logic'] ? ($lang['depOr'] ?? 'oder') : ($lang['depAnd'] ?? 'und')).' ';
        $template = (string) ('hide' === $row['mode'] ? ($lang['depHideIf'] ?? 'ausblenden, wenn %s') : ($lang['depShowIf'] ?? 'anzeigen, wenn %s'));

        return sprintf($template, implode($glue, $parts));
    }

    /**
     * 1 → ①. Beyond the circled digits Unicode has (20) the plain number is used – a list that
     * long has other problems.
     */
    private function circledNumber(int $number): string
    {
        return $number >= 1 && $number <= 20 ? '&#'.(0x2460 + $number - 1).';' : (string) $number;
    }

    /**
     * edit.buttons_callback for the answer-field / document-text dialogs. When a
     * record is edited in a dcaWizard modal, Contao passes nb=1 and therefore drops
     * the "save and close" button – the dialog then only offers "save" and has to be
     * closed with the ×. Re-add "save and close" here; dcaWizard's own
     * CloseModalListener (registered after this callback) turns the re-added button
     * into one that closes the modal (it converts an existing saveNclose button). In a
     * normal full-page edit the button already exists, so nothing is changed there.
     *
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    public function addSaveAndClose(array $buttons, DataContainer $dc): array
    {
        if (isset($buttons['saveNclose']) || null === Input::get('dcawizard')) {
            return $buttons;
        }

        $label = $GLOBALS['TL_LANG']['MSC']['saveNclose'] ?? 'Save and close';
        $close = '<button type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" data-action="contao--scroll-offset#discard">'.$label.'</button>';

        // Insert right after the "save" button.
        $out = [];

        foreach ($buttons as $key => $html) {
            $out[$key] = $html;

            if ('save' === $key) {
                $out['saveNclose'] = $close;
            }
        }

        $out['saveNclose'] ??= $close;

        return $out;
    }

    /**
     * onload_callback for tl_workflow: deletes the workflow's never-saved child
     * records. Contao's act=create inserts a blank row (tstamp=0) at once and only
     * sets a real tstamp on save; its built-in cleanup (DC_Table::reviseTable) runs
     * on the child table's own list view, which is never shown for the embedded
     * questions/rules – so abandoned "new" rows would pile up. A saved record always
     * has tstamp>0, so deleting tstamp=0 rows of this workflow is safe (a row being
     * created lives in an open modal; this runs on the workflow edit load only).
     */
    public function cleanupAbandonedChildren(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $db = System::getContainer()->get('database_connection');

        foreach (['tl_workflow_question', 'tl_workflow_rule'] as $table) {
            $db->executeStatement(
                'DELETE FROM '.$table.' WHERE pid = ? AND tstamp = 0',
                [(int) $dc->id],
            );
        }
    }

    /**
     * load_callback for tl_workflow.questionOrder: returns the workflow's current
     * answer-field order (child sorting) as a comma-separated id list, so the
     * hidden field always mirrors reality. Also loads the back end CSS that hides
     * the field's row (the order is edited by drag&drop, not in this raw field).
     *
     * @param mixed $value
     */
    public function loadQuestionOrder(mixed $value, DataContainer $dc): mixed
    {
        $GLOBALS['TL_CSS']['wf_backend'] = 'bundles/contaoworkflow/workflow-backend.css';

        if (!$dc->id) {
            return $value;
        }

        return implode(',', $this->questionIdsInOrder((int) $dc->id));
    }

    /**
     * save_callback for tl_workflow.questionOrder: renumbers the child answer
     * fields' sorting to the posted order (drag&drop) and returns the normalised
     * order to store. Because this is a real column, a changed order is picked up
     * by Contao's versioning (new version + visible diff). Questions of the
     * workflow that are missing from the posted list are appended in their current
     * order, so every row keeps a defined sorting.
     *
     * @param mixed $value
     */
    public function saveQuestionOrder(mixed $value, DataContainer $dc): mixed
    {
        if (!$dc->id) {
            return $value;
        }

        $workflowId = (int) $dc->id;
        $current = $this->questionIdsInOrder($workflowId);
        $target = $this->targetOrder($current, (string) $value);

        // A visibility condition may only reference a PRECEDING field, so the list order is
        // part of the configuration, not a display preference. Dragging a trigger below the
        // field that depends on it would leave a condition that can never be evaluated – and
        // it would do so from the parent mask, past the child record's own save callback.
        $violation = $this->firstOrderViolation($workflowId, $target);

        if (null !== $violation) {
            Message::addError($violation);

            // Keep the stored order: the reordering is discarded, not half applied.
            return implode(',', $current);
        }

        return implode(',', $this->renumberQuestions($workflowId, (string) $value));
    }

    /**
     * The first order violation of a proposed answer-field order, or null when every
     * conditional field follows the fields it depends on.
     *
     * @param array<int, int> $order question ids in the proposed order
     */
    private function firstOrderViolation(int $workflowId, array $order): ?string
    {
        System::loadLanguageFile('tl_workflow_question');

        $questions = [];

        foreach (QuestionModel::findBy('pid', $workflowId, ['order' => 'sorting']) ?? [] as $question) {
            $questions[(int) $question->id] = $question;
        }

        $available = [];

        foreach ($order as $id) {
            $question = $questions[$id] ?? null;

            if (null === $question) {
                continue;
            }

            if ($question->isConditional()) {
                foreach ($question->getConditions() as $condition) {
                    if (!isset($available[$condition['field']])) {
                        return sprintf(
                            (string) ($GLOBALS['TL_LANG']['tl_workflow_question']['condOrderViolation'] ?? 'condOrderViolation'),
                            StringUtil::specialchars((string) $question->label),
                            StringUtil::specialchars($condition['field']),
                        );
                    }
                }
            }

            $column = trim((string) $question->storageField);

            if ('' !== $column) {
                $available[$column] = true;
            }
        }

        return null;
    }

    /**
     * onrestore_version_callback for tl_workflow: re-applies a restored
     * questionOrder to the child answer fields' sorting (a version restore writes
     * the workflow row back but does not touch the child table).
     *
     * @param array<string, mixed> $data restored record data
     */
    public function restoreQuestionOrder(string $table, int $pid, int $version, array $data): void
    {
        if ('tl_workflow' !== $table) {
            return;
        }

        $this->renumberQuestions($pid, (string) ($data['questionOrder'] ?? ''));
    }

    /**
     * Child answer-field ids of a workflow in their current sorting order.
     *
     * @return array<int, int>
     */
    private function questionIdsInOrder(int $workflowId): array
    {
        $ids = [];
        $questions = QuestionModel::findBy('pid', $workflowId, ['order' => 'sorting']);

        if (null !== $questions) {
            foreach ($questions as $question) {
                $ids[] = (int) $question->id;
            }
        }

        return $ids;
    }

    /**
     * Renumbers a workflow's answer-field sorting to the given comma-separated id
     * order; ids not belonging to the workflow are dropped, missing ones appended
     * (in their current order). Writes nothing when the order is already as desired.
     *
     * @return array<int, int> the resulting order (valid ids only)
     */
    private function renumberQuestions(int $workflowId, string $order): array
    {
        $current = $this->questionIdsInOrder($workflowId);
        $target = $this->targetOrder($current, $order);

        if ($target === $current) {
            return $current;
        }

        $sorting = 0;

        foreach ($target as $id) {
            $question = QuestionModel::findByPk($id);

            if (null !== $question) {
                $question->sorting = $sorting += 64;
                $question->tstamp = time();
                $question->save();
            }
        }

        return $target;
    }

    /**
     * The order a posted id list actually results in: ids that belong to the workflow, in the
     * requested order, with the ones the list did not mention appended in their current order.
     *
     * @param array<int, int> $current
     *
     * @return array<int, int>
     */
    private function targetOrder(array $current, string $order): array
    {
        $requested = array_values(array_unique(array_filter(array_map('intval', explode(',', $order)))));
        $target = array_values(array_intersect($requested, $current));

        foreach ($current as $id) {
            if (!\in_array($id, $target, true)) {
                $target[] = $id;
            }
        }

        return $target;
    }

    /**
     * Renders the embedded PDF-rule list (dcaWizard list_callback): label +
     * readable conditions ("(Standardtext)" for a rule without conditions),
     * plus the edit/delete operations generated by the wizard itself.
     *
     * @param array<int, array<string, mixed>> $records
     */
    public function renderRulesList(array $records, string $id, object $widget): string
    {
        // Hide abandoned "new" records (act=create inserts a tstamp=0 row at once;
        // see cleanupAbandonedChildren for the DB cleanup).
        $records = array_values(array_filter($records, static fn (array $r): bool => (int) ($r['tstamp'] ?? 0) > 0));

        if ([] === $records) {
            return '<p>'.StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow']['rulesEmpty'] ?? '')).'</p>';
        }

        $hLabel = $GLOBALS['TL_LANG']['tl_workflow_rule']['title'][0] ?? 'Bezeichnung';
        $hCond = $GLOBALS['TL_LANG']['tl_workflow_rule']['conditions'][0] ?? 'Bedingung';

        $body = '';
        $defaultCount = 0;

        foreach ($records as $row) {
            $title = trim((string) ($row['title'] ?? ''));

            if ('' === $title) {
                $title = ($GLOBALS['TL_LANG']['tl_workflow_rule']['untitled'] ?? 'Rule').' '.(int) ($row['id'] ?? 0);
            }

            $isDefault = '1' === (string) ($row['isDefault'] ?? '');

            if ($isDefault) {
                ++$defaultCount;
                $conditionText = '<em>('.StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow_rule']['alwaysLabel'] ?? 'Standardtext')).')</em>';
            } else {
                $conditionText = $this->formatConditions($row['conditions'] ?? null) ?: '–';
            }

            $operations = $widget->generateRowOperation('edit', $row).$widget->generateRowOperation('delete', $row);

            $body .= '<tr class="hover-row">'
                .'<td class="tl_file_list">'.StringUtil::specialchars($title).'</td>'
                .'<td class="tl_file_list">'.$conditionText.'</td>'
                .'<td class="tl_file_list tl_right_nowrap">'.$operations.'</td>'
                .'</tr>';
        }

        // There should be exactly one rule without conditions (the "Standardtext").
        $warning = '';

        if ($defaultCount > 1) {
            $warning = '<p class="tl_error">'.StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow_rule']['defaultRuleError'] ?? '')).'</p>';
        } elseif (0 === $defaultCount) {
            $warning = '<p class="tl_info">'.StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow_rule']['defaultMissing'] ?? '')).'</p>';
        }

        return $warning.'<table class="tl_listing showColumns"><thead><tr>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hLabel).'</th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hCond).'</th>'
            .'<th class="tl_folder_tlist"></th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    /**
     * Human-readable summary of a rule's conditions; empty string when none.
     *
     * @param mixed $value serialized conditions
     */
    private function formatConditions(mixed $value): string
    {
        $conditions = $this->extractConditions($value);

        if ([] === $conditions) {
            return '';
        }

        $operators = $GLOBALS['TL_LANG']['tl_workflow_rule']['operatorOptions'] ?? [];
        $parts = [];

        foreach ($conditions as $condition) {
            $operatorLabel = (string) ($operators[$condition['operator']] ?? $condition['operator']);
            $value = \in_array($condition['operator'], ['empty', 'notempty'], true) ? '' : ' '.$condition['value'];
            $parts[] = trim($condition['field'].' '.$operatorLabel.$value);
        }

        $and = ' '.($GLOBALS['TL_LANG']['tl_workflow_rule']['condAnd'] ?? 'und').' ';

        return StringUtil::specialchars(implode($and, $parts));
    }

    /**
     * save_callback for tl_workflow_rule.conditions: a default rule ("Standardtext")
     * always applies, so it must not keep conditions. The conditions wizard is hidden
     * client-side while "isDefault" is checked (data-wf-toggle); this clears any
     * leftover value on save, regardless of the client state.
     *
     * @param mixed $value serialized conditions
     */
    public function clearConditionsForDefaultRule(mixed $value, DataContainer $dc): mixed
    {
        return Input::post('isDefault') ? serialize([]) : $value;
    }

    /**
     * Complete conditions (rows with field + operator) of a serialized value.
     *
     * @param mixed $value
     *
     * @return array<int, array{field: string, operator: string, value: string}>
     */
    private function extractConditions(mixed $value): array
    {
        $conditions = [];

        foreach (StringUtil::deserialize($value, true) as $row) {
            $field = trim((string) ($row['field'] ?? ''));
            $operator = trim((string) ($row['operator'] ?? ''));

            if ('' !== $field && '' !== $operator) {
                $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => (string) ($row['value'] ?? '')];
            }
        }

        return $conditions;
    }

    /**
     * Options for a rule condition's "field" column: the workflow's answer-field
     * storage columns that actually exist in the source file. A stored value that
     * is not (or no longer) valid – e.g. on a copy without a source file – is kept
     * and shown as "Unbekannte Option: …", mirroring the answer-field dropdown.
     *
     * @return array<string, string>
     */
    public function getStorageFieldOptions(): array
    {
        $workflow = WorkflowModel::findByPk($this->resolveRuleWorkflowId());

        if (null === $workflow) {
            return [];
        }

        $headers = $this->sourceHeaders($workflow);
        $options = [];

        foreach ($workflow->getStorageFields() as $field) {
            if (\in_array($field, $headers, true)) {
                $options[$field] = $field;
            }
        }

        $label = (string) ($GLOBALS['TL_LANG']['tl_workflow_rule']['unknownOption'] ?? 'Unbekannte Option: %s');

        foreach ($this->currentRuleFields() as $field) {
            if ('' !== $field && !isset($options[$field])) {
                $options[$field] = sprintf($label, $field);
            }
        }

        return $options;
    }

    /**
     * Options for a visibility condition's "field" column: the storage columns of the form
     * fields that PRECEDE the one being edited.
     *
     * Only preceding fields, because that is what makes the whole feature simple: no cycles,
     * a single evaluation pass in list order, and a form that can be filled top-down. Only
     * fields WITH a storage column, because a condition names the column (not the field) –
     * that is what survives a copy and lets the document composer recompute the visibility
     * from the stored data alone. Fields that never reach the form ("Aktuelle Zeit" hidden in
     * the form) are left out as well: the browser cannot read a value that has no input.
     *
     * @return array<string, string>
     */
    public function getConditionSourceOptions(): array
    {
        $options = [];

        foreach ($this->conditionSourceQuestions() as $column => $question) {
            $options[$column] = trim((string) $question->label).' ('.$column.')';
        }

        // Keep a stored value that is no longer offered visible and selectable, mirroring the
        // answer-field dropdown – otherwise saving the record would silently drop it.
        $label = (string) ($GLOBALS['TL_LANG']['tl_workflow_question']['unknownOption'] ?? 'Unbekannte Option: %s');
        [, $questionId] = $this->resolveQuestionContext();

        foreach ($this->currentQuestionConditionFields($questionId) as $field) {
            if (!isset($options[$field])) {
                $options[$field] = sprintf($label, $field);
            }
        }

        return $options;
    }

    /**
     * The form fields that may act as a trigger for the one being edited, keyed by their
     * storage column: everything PRECEDING it in the list that has a storage column and
     * actually appears in the form.
     *
     * @return array<string, QuestionModel>
     */
    private function conditionSourceQuestions(): array
    {
        [$workflowId, $questionId] = $this->resolveQuestionContext();

        if ($workflowId < 1) {
            return [];
        }

        $sources = [];

        foreach (QuestionModel::findBy('pid', $workflowId, ['order' => 'sorting']) ?? [] as $question) {
            // Everything from the edited field onwards is "not preceding".
            if ($questionId > 0 && (int) $question->id === $questionId) {
                break;
            }

            $column = trim((string) $question->storageField);

            if ('' === $column || isset($sources[$column]) || $question->isHiddenInForm()) {
                continue;
            }

            $sources[$column] = $question;
        }

        return $sources;
    }

    /**
     * load_callback for tl_workflow_question.conditions: hands the browser the answer options
     * of the possible trigger fields, so the "Vergleichswert" column can offer them instead of
     * asking the user to retype a value.
     *
     * What is compared is the STORED value ("ja"), not the visible option text
     * ("Einverstanden") – a distinction that is easy to get wrong by hand and impossible to
     * get wrong from a list. Only choice fields have a closed set of answers; for a text,
     * number or date trigger the column stays a plain input, and a small switch next to the
     * control moves between list and free text in both directions.
     *
     * The options travel as JSON in a script tag rather than as widget attributes: the
     * MultiColumnWizard builds its columns from a fixed configuration, and which options
     * belong in a row only becomes clear from the field chosen IN that row – a question that
     * can only be answered in the browser (see workflow-condition-value.js).
     *
     * @param mixed $value
     */
    public function loadConditionValueOptions(mixed $value, DataContainer $dc): mixed
    {
        $columns = [];

        foreach ($this->conditionSourceQuestions() as $column => $question) {
            if (!$question->hasOptions()) {
                continue;
            }

            $options = [];

            foreach ($question->getOptions() as $option) {
                $options[] = ['v' => $option['value'], 'l' => $option['label']];
            }

            if ([] !== $options) {
                $columns[$column] = $options;
            }
        }

        $lang = $GLOBALS['TL_LANG']['tl_workflow_question'] ?? [];
        // Shipped even without a single choice field among the predecessors: the script also
        // greys out the value of an operator that does not use one ("ist leer"), and that must
        // behave the same in every mask.
        $payload = [
            // Cast: an empty PHP array encodes as "[]", and the browser looks columns up by
            // name – an object is what it expects, empty or not.
            'columns' => (object) $columns,
            'labels'  => [
                'blank'   => '-',
                'unknown' => (string) ($lang['condValueOffList'] ?? '%s (nicht in der Optionsliste)'),
                'free'    => (string) ($lang['condValueFree'] ?? 'Freitext eingeben'),
                'list'    => (string) ($lang['condValueList'] ?? 'Aus Liste wählen'),
                'inert'   => (string) ($lang['condValueInert'] ?? 'Dieser Operator verwendet keinen Vergleichswert.'),
            ],
        ];

        $GLOBALS['TL_JAVASCRIPT']['wf_condvalue'] = 'bundles/contaoworkflow/workflow-condition-value.js|static';
        // TL_MOOTOOLS, not TL_HEAD: the back-end template renders only the former (at the end
        // of the body, which is in time for DOMContentLoaded).
        //
        // JSON_HEX_TAG: the payload carries user-authored option labels and is embedded in the
        // page, so a "</script>" in a label must not be able to end the tag.
        $GLOBALS['TL_MOOTOOLS']['wf_condvalue'] = '<script type="application/json" id="wf-condition-options">'
            .json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE).'</script>';

        return $value;
    }

    /**
     * Operators offered for a visibility condition: deliberately a SUBSET of the PDF rule
     * operators (no ordering comparisons).
     *
     * The browser mirrors this comparison live while the form is being filled in. String
     * operators mirror in a few lines and cannot disagree with PHP; "greater than" would drag
     * the German number parsing and the date normalisation into the mirror, where a single
     * divergence means the participant sees a different form than the server assumes. The
     * ordering operators stay available where only PHP evaluates them – the PDF rules.
     *
     * @return array<string, string>
     */
    public function getConditionOperatorOptions(): array
    {
        // The wording lives with the rules; there must be one set of operator labels.
        System::loadLanguageFile('tl_workflow_rule');

        $labels = $GLOBALS['TL_LANG']['tl_workflow_rule']['operatorOptions'] ?? [];
        $options = [];

        foreach (['eq', 'neq', 'contains', 'empty', 'notempty'] as $operator) {
            $options[$operator] = (string) ($labels[$operator] ?? $operator);
        }

        return $options;
    }

    /**
     * save_callback for tl_workflow_question.conditions: normalises the wizard rows and
     * refuses a configuration that cannot work.
     *
     * Refused (the save fails): a condition on the field itself or on a field that does not
     * precede it, and a visibility mode without a single complete condition – the latter
     * would otherwise reach the runtime, which deliberately falls back to "visible" and would
     * leave the user wondering why their setting does nothing.
     *
     * Reported without blocking: a comparison value that matches none of the trigger's
     * options (the classic "Ja" vs. "ja"), and a value comparison on a number/date trigger,
     * where the stored spelling decides (see getConditionOperatorOptions).
     *
     * @param mixed $value serialized conditions
     */
    public function validateConditions(mixed $value, DataContainer $dc): mixed
    {
        System::loadLanguageFile('tl_workflow_question');

        [$workflowId, $questionId] = $this->resolveQuestionContext();
        $question = $questionId > 0 ? QuestionModel::findByPk($questionId) : null;

        // The stored mode when the field was not posted at all (a mass edit posts suffixed
        // names) – same rule as postedDecimals(). Reading a missing POST value as "immer
        // anzeigen" would silently drop the conditions of every field it touches.
        $posted = Input::post('conditionMode');
        $mode = (string) (null !== $posted ? $posted : ($question->conditionMode ?? ''));

        // "immer anzeigen": the wizard is hidden client-side, its leftover value goes here –
        // same contract as the PDF rules' default text.
        if (!\in_array($mode, ['show', 'hide'], true)) {
            return serialize([]);
        }

        $conditions = $this->extractConditions($value);

        if ([] === $conditions) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_workflow_question']['condEmpty'] ?? 'condEmpty'));
        }

        $allowed = $this->getConditionSourceOptions();
        $own = trim((string) ($question->storageField ?? ''));

        foreach ($conditions as $condition) {
            $field = $condition['field'];

            if ('' !== $own && $field === $own) {
                throw new \RuntimeException(sprintf(
                    (string) ($GLOBALS['TL_LANG']['tl_workflow_question']['condSelfRef'] ?? 'condSelfRef'),
                    $field,
                ));
            }

            if (!isset($allowed[$field])) {
                throw new \RuntimeException(sprintf(
                    (string) ($GLOBALS['TL_LANG']['tl_workflow_question']['condForwardRef'] ?? 'condForwardRef'),
                    $field,
                ));
            }

            $this->reportConditionHints($condition, $workflowId);
        }

        return serialize($conditions);
    }

    /**
     * Non-blocking hints for one condition (see validateConditions).
     *
     * @param array{field: string, operator: string, value: string} $condition
     */
    private function reportConditionHints(array $condition, int $workflowId): void
    {
        $trigger = $this->findQuestionByColumn($workflowId, $condition['field']);

        if (null === $trigger || \in_array($condition['operator'], ['empty', 'notempty'], true)) {
            return;
        }

        $label = trim((string) $trigger->label);

        if ($trigger->hasOptions()) {
            $values = $trigger->getAllowedValues();

            if ([] !== $values && !\in_array($condition['value'], $values, true)) {
                Message::addInfo(sprintf(
                    StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow_question']['condValueUnknown'] ?? 'condValueUnknown')),
                    StringUtil::specialchars($condition['value']),
                    StringUtil::specialchars($label),
                ));
            }

            return;
        }

        if (\in_array((string) $trigger->type, ['number', 'date'], true)) {
            Message::addInfo(sprintf(
                StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow_question']['condTypeHint'] ?? 'condTypeHint')),
                StringUtil::specialchars($label),
            ));
        }
    }

    /**
     * The form field of a workflow that stores into the given column (the first one in list
     * order), or null.
     */
    private function findQuestionByColumn(int $workflowId, string $column): ?QuestionModel
    {
        foreach (QuestionModel::findBy('pid', $workflowId, ['order' => 'sorting']) ?? [] as $question) {
            if (trim((string) $question->storageField) === $column) {
                return $question;
            }
        }

        return null;
    }

    /**
     * Condition fields of the form field currently being edited (raw, incl. rows that are no
     * longer valid), so a stored value stays visible in the dropdown.
     *
     * @return array<int, string>
     */
    private function currentQuestionConditionFields(int $questionId): array
    {
        $question = $questionId > 0 ? QuestionModel::findByPk($questionId) : null;

        if (null === $question) {
            return [];
        }

        $fields = [];

        foreach (StringUtil::deserialize($question->conditions, true) as $row) {
            $field = trim((string) ($row['field'] ?? ''));

            if ('' !== $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Parent workflow and id of the form field a callback is currently working on, resolved
     * from the request (the MultiColumnWizard callbacks receive the wizard, not the
     * DataContainer – see getStorageFieldOptions).
     *
     * On create the id is 0: the new record is appended at the end of the list, so every
     * existing field of the workflow precedes it.
     *
     * @return array{0: int, 1: int} [workflow id, question id]
     */
    private function resolveQuestionContext(): array
    {
        $questionId = (int) Input::get('id');

        if ($questionId > 0 && 'create' !== Input::get('act')) {
            $question = QuestionModel::findByPk($questionId);

            if (null !== $question) {
                return [(int) $question->pid, $questionId];
            }
        }

        $pid = (int) Input::get('pid');

        if ($pid < 1) {
            return [0, 0];
        }

        if (1 === (int) Input::get('mode')) {
            $sibling = QuestionModel::findByPk($pid);

            return [null !== $sibling ? (int) $sibling->pid : 0, 0];
        }

        return [$pid, 0];
    }

    /**
     * Refuses a storage column whose Excel formatting a "number" field cannot round-trip,
     * and snapshots the surviving format onto the question.
     *
     * A number field shows the stored value, lets the participant edit it and writes it
     * back. That contract only holds while the column's format is reproducible – three
     * decimals would be silently rounded, a percent format scaled by 100. So the column is
     * checked here, at save time, instead of corrupting values later. The snapshot is what
     * lets the form, the live preview, the PDF and the export agree afterwards without
     * re-reading the (expensive) style layer of the source file.
     *
     * Runs as save_callback on storageField: "type" sits before it in the palette, so its
     * posted value is already available.
     */
    /**
     * Warns – without blocking – when a field is switched to "number" although its storage
     * column cannot back one.
     *
     * The strict check lives on storageField and refuses the save. That is the right
     * instrument while the column can still be changed. Once answers exist, storageField is
     * locked and therefore never posted, so that check cannot run: the mismatch would only
     * show up at the next import. Blocking would be wrong here as well – with the column
     * locked, the only remedies are a different field type or a change in the source file,
     * and refusing the save would just trap the user. So this reports and lets the save
     * through; the import reports it again and keeps the previous format.
     */
    public function warnOnTypeMismatch(mixed $value, DataContainer $dc): mixed
    {
        // storageField posted means the strict check runs anyway – no second opinion needed.
        if ('number' !== (string) $value || null !== Input::post('storageField')) {
            return $value;
        }

        $question = QuestionModel::findByPk((int) ($dc->id ?? 0));
        $column = trim((string) ($question->storageField ?? ''));
        $workflow = null !== $question ? WorkflowModel::findByPk((int) $question->pid) : null;

        if ('' === $column || null === $workflow) {
            return $value;
        }

        $container = System::getContainer();
        $result = $container->get(ColumnCompatibility::class)->checkNumberColumn(
            $column,
            $container->get(ColumnFormatAnalyzer::class)->analyze($workflow, $column),
            $this->postedDecimals((string) ($question->numberDecimals ?? '')),
        );

        if (!$result->isCompatible()) {
            // Fully escaped: Contao embeds the message raw into the page, and the problems
            // carry column names straight from the source file.
            Message::addError(sprintf(
                'Feldtyp „Zahl" passt nicht zur Spalte: %s',
                StringUtil::specialchars(implode(' ', $result->problems)),
            ));
        }

        return $value;
    }

    public function validateNumberColumn(mixed $value, DataContainer $dc): mixed
    {
        $column = trim((string) $value);

        // Only number fields have the round-trip contract; every other type (notably
        // "text") accepts whatever the column holds.
        if ('' === $column || 'number' !== (string) Input::post('type')) {
            return $value;
        }

        $workflow = WorkflowModel::findByPk((int) ($dc->activeRecord->pid ?? 0));

        if (null === $workflow) {
            return $value;
        }

        $container = System::getContainer();
        $decimals = $this->postedDecimals((string) ($dc->activeRecord->numberDecimals ?? ''));
        $result = $container->get(ColumnCompatibility::class)->checkNumberColumn(
            $column,
            $container->get(ColumnFormatAnalyzer::class)->analyze($workflow, $column),
            $decimals,
        );

        if (!$result->isCompatible()) {
            throw new \RuntimeException(implode(' ', $result->problems));
        }

        // Stored alongside the field, not recomputed on read: the source file may be
        // replaced or gone by the time the form is rendered.
        System::getContainer()->get('database_connection')->update(
            'tl_workflow_question',
            ['numberFormat' => json_encode($result->format?->toArray(), JSON_THROW_ON_ERROR)],
            ['id' => (int) $dc->id],
        );

        return $value;
    }

    /**
     * The decimals the field will have after this save: the posted value if the form
     * carried one, otherwise the stored one. A configured value lifts the column's decimal
     * rules, so the check has to judge by what is being saved, not by what was there.
     *
     * The field is not posted at all when the type toggle hides it (hidden fields are
     * disabled); the stored value is the right answer then.
     */
    private function postedDecimals(string $stored): ?int
    {
        $posted = Input::post('numberDecimals');
        $value = trim((string) (null !== $posted ? $posted : $stored));

        return '' === $value ? null : max(0, (int) $value);
    }

    /**
     * Source column names of a workflow; empty when no readable source file is configured.
     *
     * The local cache this used to keep is gone: SpreadsheetInspector memoises the headers
     * itself now, for every caller rather than just this one.
     *
     * @return array<int, string>
     */
    private function sourceHeaders(WorkflowModel $workflow): array
    {
        $inspector = System::getContainer()->get(SpreadsheetInspector::class);

        return array_keys($inspector->getHeaderOptions($workflow));
    }

    /**
     * Field values of the rule currently being edited (raw, incl. incomplete
     * rows), so an unknown stored value stays visible/selectable.
     *
     * @return array<int, string>
     */
    private function currentRuleFields(): array
    {
        $ruleId = (int) Input::get('id');

        if ($ruleId < 1 || 'create' === Input::get('act')) {
            return [];
        }

        $rule = RuleModel::findByPk($ruleId);

        if (null === $rule) {
            return [];
        }

        $fields = [];

        foreach (StringUtil::deserialize($rule->conditions, true) as $row) {
            $field = trim((string) ($row['field'] ?? ''));

            if ('' !== $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Resolves the parent workflow id of the rule currently being edited or
     * created. On edit "id" is the rule; on create "pid" is either the parent
     * workflow (PASTE_INTO) or a sibling rule (PASTE_AFTER, mode 1).
     */
    private function resolveRuleWorkflowId(): int
    {
        $ruleId = (int) Input::get('id');

        if ($ruleId > 0 && 'create' !== Input::get('act')) {
            $rule = RuleModel::findByPk($ruleId);

            if (null !== $rule) {
                return (int) $rule->pid;
            }
        }

        $pid = (int) Input::get('pid');

        if ($pid < 1) {
            return 0;
        }

        if (1 === (int) Input::get('mode')) {
            $sibling = RuleModel::findByPk($pid);

            return null !== $sibling ? (int) $sibling->pid : 0;
        }

        return $pid;
    }
}
