<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\EventListener\DataContainer;

use Contao\Input;
use Contao\StringUtil;
use Psimandl\TrainerWorkflowBundle\Model\RuleModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;

/**
 * Presentational and option callbacks for the answer-field (tl_trainer_question)
 * and PDF-rule (tl_trainer_rule) child tables.
 *
 * Must stay free of constructor dependencies: the MultiColumnWizard and the
 * legacy child_record callback resolve this class via System::importStatic(),
 * which instantiates it without container injection.
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
        $typeLabel = $GLOBALS['TL_LANG']['tl_trainer_question']['typeOptions'][$type] ?? $type;
        $storage = StringUtil::specialchars((string) ($row['storageField'] ?? ''));

        return sprintf(
            '<div class="tl_content_left"><strong>%s</strong> <span style="color:#999">[%s &rarr; %s]</span></div>',
            $label,
            StringUtil::specialchars((string) $typeLabel),
            $storage,
        );
    }

    /**
     * Renders one PDF-rule row in the workflow's child view.
     *
     * @param array<string, mixed> $row
     */
    public function renderRuleRecord(array $row): string
    {
        $title = (string) ($row['title'] ?? '');
        $count = \count(StringUtil::deserialize($row['conditions'] ?? null, true));
        $template = StringUtil::specialchars((string) ($row['bodyTemplate'] ?? ''));

        if ('' === $title) {
            $title = ($GLOBALS['TL_LANG']['tl_trainer_rule']['untitled'] ?? 'Rule').' '.(int) ($row['id'] ?? 0);
        }

        return sprintf(
            '<div class="tl_content_left"><strong>%s</strong> <span style="color:#999">[%d &times; Bedingung &rarr; %s]</span></div>',
            StringUtil::specialchars($title),
            $count,
            $template,
        );
    }

    /**
     * Options for a rule condition's "field" column: the storage fields
     * configured on the rule's parent workflow.
     *
     * @return array<string, string>
     */
    public function getStorageFieldOptions(): array
    {
        $workflow = WorkflowModel::findByPk($this->resolveRuleWorkflowId());

        if (null === $workflow) {
            return [];
        }

        $fields = $workflow->getStorageFields();

        return [] === $fields ? [] : array_combine($fields, $fields);
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
