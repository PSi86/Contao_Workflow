<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Input;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\RuleModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\PlaceholderResolver;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;

/**
 * Adds a small placeholder helper (autocomplete) to the workflow's placeholder
 * fields: typing surfaces the available ##data_*## / ##var_*## / ##email## /
 * ##workflow_title## tokens and filters them as you type. The token list is built
 * per record from the workflow's source columns, answer fields and letterhead
 * variables; the slugs are produced with PlaceholderResolver so every suggestion
 * matches exactly the token replaced later in the PDF and the e-mails.
 *
 * The UI is a self-contained asset (public/workflow-placeholders.js/.css). Unlike
 * the Notification Center helper it recognises a ##…## token fragment at the caret
 * even when it is glued to surrounding text (e.g. the PDF file-name pattern), so it
 * also pops up while editing an existing placeholder.
 */
class PlaceholderHelperListener
{
    public function __construct(
        private readonly SpreadsheetInspector $inspector,
        private readonly PlaceholderResolver $placeholders,
    ) {
    }

    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function enableForWorkflow(DataContainer $dc): void
    {
        $workflow = $dc->id ? WorkflowModel::findByPk((int) $dc->id) : null;

        // The file name resolves without statement tokens, the title with them.
        $this->applyTo('tl_workflow', ['pdfFileName'], $workflow, false);
        $this->applyTo('tl_workflow', ['pdfTitle'], $workflow, true);
    }

    #[AsCallback(table: 'tl_workflow_rule', target: 'config.onload')]
    public function enableForRule(DataContainer $dc): void
    {
        $this->applyTo('tl_workflow_rule', ['pdfBody'], $this->resolveRuleWorkflow($dc), true);
    }

    #[AsCallback(table: 'tl_workflow_question', target: 'config.onload')]
    public function enableForQuestion(DataContainer $dc): void
    {
        // Statements may use ##value## plus the regular tokens, but no ##stmt_*##
        // (statements do not nest).
        $this->applyTo('tl_workflow_question', ['pdfStatement'], $this->resolveQuestionWorkflow($dc), false);
    }

    /**
     * Wires the autosuggester onto the given fields and registers the helper assets.
     * Only runs on the actual edit mask so the parent list view stays untouched.
     *
     * @param array<int, string> $fields
     */
    private function applyTo(string $table, array $fields, ?WorkflowModel $workflow, bool $withStatements): void
    {
        if (!\in_array((string) Input::get('act'), ['edit', 'editAll'], true)) {
            return;
        }

        $json = json_encode($this->buildTokens($workflow, $withStatements), JSON_THROW_ON_ERROR);

        foreach ($fields as $field) {
            if (!isset($GLOBALS['TL_DCA'][$table]['fields'][$field])) {
                continue;
            }

            $eval = &$GLOBALS['TL_DCA'][$table]['fields'][$field]['eval'];
            $eval['data-wf-autosuggest'] = $json;
            // Keep browser/password-manager autocompletion from covering the helper.
            $eval['autocomplete'] = false;
            $eval['data-1p-ignore'] = 'true';
            $eval['data-lpignore'] = 'true';
            $eval['data-bwignore'] = 'true';
            unset($eval);
        }

        $this->loadAssets();
    }

    private function loadAssets(): void
    {
        $GLOBALS['TL_CSS']['wf_placeholders'] = 'bundles/contaoworkflow/workflow-placeholders.css';
        $GLOBALS['TL_JAVASCRIPT']['wf_placeholders'] = 'bundles/contaoworkflow/workflow-placeholders.js|static';
    }

    /**
     * Builds the helper's token list as a list of {name, label}. The controller
     * itself wraps each name in "##…##", so the bare slug is passed. De-duplicated
     * by slug and kept in a readable order (source columns, answer fields,
     * letterhead variables, then the fixed tokens).
     *
     * @return array<int, array{name: string, label: string}>
     */
    private function buildTokens(?WorkflowModel $workflow, bool $withStatements = false): array
    {
        $tokens = [];
        $seen = [];

        $add = static function (string $name, string $label) use (&$tokens, &$seen): void {
            if ('' === $name || isset($seen[$name])) {
                return;
            }

            $seen[$name] = true;
            $tokens[] = ['name' => $name, 'label' => $label];
        };

        if (null !== $workflow) {
            foreach ($this->inspector->getHeaders($workflow) as $name) {
                $add('data_'.$this->placeholders->normalize($name), 'Spalte: '.$name);
            }

            $hasStatements = false;

            foreach ($workflow->getQuestions() as $question) {
                $field = trim((string) $question->storageField);

                if ('' === $field) {
                    continue;
                }

                $label = trim((string) $question->label);
                $add('data_'.$this->placeholders->normalize($field), 'Antwortfeld: '.('' !== $label ? $label : $field));

                if ($withStatements && !$question->isDisplay()) {
                    $hasStatements = true;
                    $add('stmt_'.$this->placeholders->normalize($field), 'Textbaustein: '.('' !== $label ? $label : $field));
                }
            }

            if ($hasStatements) {
                $add('stmt_all', 'Alle Textbausteine (in Feld-Reihenfolge)');
            }

            foreach ($this->masterVars($workflow) as $key) {
                $add('var_'.$this->placeholders->normalize($key), 'Briefpapier: '.$key);
            }
        }

        $add('email', 'E-Mail-Adresse des Empfängers');
        $add('workflow_title', 'Titel des Workflows');

        return $tokens;
    }

    /**
     * Resolves the parent workflow of the answer field currently being edited or
     * created (mirrors resolveRuleWorkflow for tl_workflow_question).
     */
    private function resolveQuestionWorkflow(DataContainer $dc): ?WorkflowModel
    {
        if (isset($dc->activeRecord->pid) && (int) $dc->activeRecord->pid > 0) {
            $pid = (int) $dc->activeRecord->pid;
        } elseif ($dc->id && 'create' !== Input::get('act')) {
            $question = QuestionModel::findByPk((int) $dc->id);
            $pid = null !== $question ? (int) $question->pid : 0;
        } else {
            $pid = (int) Input::get('pid');

            if (1 === (int) Input::get('mode') && $pid > 0) {
                $sibling = QuestionModel::findByPk($pid);
                $pid = null !== $sibling ? (int) $sibling->pid : 0;
            }
        }

        return $pid > 0 ? WorkflowModel::findByPk($pid) : null;
    }

    /**
     * Variable keys (##var_*##) of the workflow's assigned master: the master's own
     * key/value pairs, completed with the keys declared for its template.
     *
     * @return array<int, string>
     */
    private function masterVars(WorkflowModel $workflow): array
    {
        $master = (int) $workflow->master > 0 ? MasterModel::findByPk((int) $workflow->master) : null;

        if (null === $master) {
            return [];
        }

        $keys = array_keys($master->getPdfData());

        $declared = $GLOBALS['TL_WORKFLOW_PDF_VARS'][$master->getMasterTemplate()] ?? [];

        foreach (array_keys($declared) as $key) {
            if (!\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Resolves the parent workflow of the PDF rule currently being edited or
     * created (on edit "id" is the rule; on create/paste "pid" is the workflow or,
     * in PASTE_AFTER mode, a sibling rule).
     */
    private function resolveRuleWorkflow(DataContainer $dc): ?WorkflowModel
    {
        if (isset($dc->activeRecord->pid) && (int) $dc->activeRecord->pid > 0) {
            $pid = (int) $dc->activeRecord->pid;
        } elseif ($dc->id && 'create' !== Input::get('act')) {
            $rule = RuleModel::findByPk((int) $dc->id);
            $pid = null !== $rule ? (int) $rule->pid : 0;
        } else {
            $pid = (int) Input::get('pid');

            if (1 === (int) Input::get('mode') && $pid > 0) {
                $sibling = RuleModel::findByPk($pid);
                $pid = null !== $sibling ? (int) $sibling->pid : 0;
            }
        }

        return $pid > 0 ? WorkflowModel::findByPk($pid) : null;
    }
}
