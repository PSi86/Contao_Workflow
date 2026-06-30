<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
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
    /** @var array<int, array<int, string>> per-request cache of source headers by workflow id */
    private static array $headerCache = [];

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

        return sprintf(
            '<div class="tl_content_left"><strong>%s</strong> <span style="color:#999">[%s &rarr; %s]</span></div>',
            $label,
            StringUtil::specialchars((string) $typeLabel),
            $storage,
        );
    }

    /**
     * Renders the embedded answer-field list (dcaWizard list_callback) with a
     * drag handle per row: the order is changed directly in this list (HTML5
     * drag&drop, see public/workflow-question-sort.js) and persisted via the
     * workflow_question_sort route – no extra dialog needed.
     *
     * @param array<int, array<string, mixed>> $records
     */
    public function renderQuestionsList(array $records, string $id, object $widget): string
    {
        if ([] === $records) {
            return '<p>'.StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow']['questionsEmpty'] ?? '')).'</p>';
        }

        $container = System::getContainer();
        $sortUrl = $container->get('router')->generate('workflow_question_sort', ['id' => (int) $widget->currentRecord]);
        $requestToken = $container->get('contao.csrf.token_manager')->getDefaultTokenValue();

        $GLOBALS['TL_CSS']['wf_backend'] = 'bundles/contaoworkflow/workflow-backend.css';
        $GLOBALS['TL_JAVASCRIPT']['wf_qsort'] = 'bundles/contaoworkflow/workflow-question-sort.js|static';

        $lang = $GLOBALS['TL_LANG']['tl_workflow_question'] ?? [];
        $hLabel = $lang['label'][0] ?? 'Beschriftung';
        $hType = $lang['type'][0] ?? 'Typ';
        $hStorage = $lang['storageField'][0] ?? 'Speicherfeld';
        $hMandatory = $lang['mandatory'][0] ?? 'Pflichtfeld';
        $typeLabels = $lang['typeOptions'] ?? [];

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

            $body .= '<tr class="hover-row" data-question-id="'.(int) $row['id'].'">'
                .'<td class="tl_file_list tw-drag-handle" draggable="true" title="'
                .StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_workflow']['questionsDrag'] ?? 'Ziehen, um die Reihenfolge zu ändern')).'">&#x2630;</td>'
                .'<td class="tl_file_list"><strong>'.StringUtil::specialchars((string) ($row['label'] ?? '')).'</strong></td>'
                .'<td class="tl_file_list">'.$typeText.'</td>'
                .'<td class="tl_file_list">'.StringUtil::specialchars((string) ($row['storageField'] ?? '')).'</td>'
                .'<td class="tl_file_list">'.('1' === (string) ($row['mandatory'] ?? '') ? '&#x2713;' : '&ndash;').'</td>'
                .'<td class="tl_file_list tl_right_nowrap">'.$operations.'</td>'
                .'</tr>';
        }

        return '<div data-question-sort data-sort-url="'.StringUtil::specialchars($sortUrl).'" data-rt="'.StringUtil::specialchars($requestToken).'">'
            .'<table class="tl_listing showColumns"><thead><tr>'
            .'<th class="tl_folder_tlist"></th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hLabel).'</th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hType).'</th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hStorage).'</th>'
            .'<th class="tl_folder_tlist">'.StringUtil::specialchars((string) $hMandatory).'</th>'
            .'<th class="tl_folder_tlist"></th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }

    /**
     * onload_callback for tl_workflow_question: the "Aktuelle Zeit" field is
     * filled automatically on submission, so "Pflichtfeld", "Vorbelegen" and
     * "Schreibgeschützt" are meaningless – remove them from the edit palette
     * for that type. Type changes (submitOnChange) re-run this callback.
     */
    public function hideMandatoryForCurrentTime(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $question = QuestionModel::findByPk((int) $dc->id);

        if (null === $question || 'currentTime' !== (string) $question->type) {
            return;
        }

        $palette = &$GLOBALS['TL_DCA']['tl_workflow_question']['palettes']['default'];
        $palette = str_replace([',mandatory', ',prefill', ',readOnly'], '', (string) $palette);
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
     * Source column names of a workflow (cached per request); empty when no
     * readable source file is configured.
     *
     * @return array<int, string>
     */
    private function sourceHeaders(WorkflowModel $workflow): array
    {
        $id = (int) $workflow->id;

        if (!isset(self::$headerCache[$id])) {
            $inspector = System::getContainer()->get(SpreadsheetInspector::class);
            self::$headerCache[$id] = array_keys($inspector->getHeaderOptions($workflow));
        }

        return self::$headerCache[$id];
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
