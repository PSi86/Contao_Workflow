<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\Controller;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Input;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;

/**
 * Populates the back end field pickers of tl_workflow directly from the
 * uploaded source spreadsheet (sheet names and column headers).
 */
class WorkflowOptionsListener
{
    public function __construct(private readonly SpreadsheetInspector $inspector)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_workflow', target: 'fields.sourceSheet.options')]
    public function getSheetOptions(DataContainer $dc): array
    {
        $workflow = $this->getWorkflow($dc);

        return null === $workflow ? [] : $this->inspector->getSheetNames($workflow);
    }

    /**
     * Shared by the workflow's header-based fields.
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_workflow', target: 'fields.emailField.options')]
    #[AsCallback(table: 'tl_workflow', target: 'fields.pdfSignatureLocation.options')]
    public function getHeaderOptions(DataContainer $dc): array
    {
        $workflow = $this->getWorkflow($dc);

        return null === $workflow ? [] : $this->inspector->getHeaderOptions($workflow);
    }

    /**
     * Source columns offered as the storage field of an answer field
     * (tl_workflow_question), resolved from the question's parent workflow.
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_workflow_question', target: 'fields.storageField.options')]
    public function getQuestionStorageOptions(DataContainer $dc): array
    {
        $workflow = WorkflowModel::findByPk($this->resolveQuestionWorkflowId($dc));

        return null === $workflow ? [] : $this->inspector->getHeaderOptions($workflow);
    }

    /**
     * Body templates ("pdf_body_*") a PDF rule (tl_workflow_rule) can select.
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_workflow_rule', target: 'fields.bodyTemplate.options')]
    public function getRuleBodyTemplateOptions(): array
    {
        return Controller::getTemplateGroup('pdf_body_');
    }

    /**
     * Pre-fills the steps when empty (new workflow), so the field is not blank.
     */
    #[AsCallback(table: 'tl_workflow', target: 'fields.steps.load')]
    public function defaultSteps(mixed $value): mixed
    {
        return [] === StringUtil::deserialize($value, true)
            ? serialize(['Importiert', 'Eingeladen', 'Beantwortet'])
            : $value;
    }

    /**
     * Offers the available PDF body templates (all "pdf_body_*" templates) so the
     * admin selects one instead of typing the exact name.
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_workflow', target: 'fields.pdfBodyTemplate.options')]
    public function getBodyTemplateOptions(): array
    {
        return Controller::getTemplateGroup('pdf_body_');
    }

    /**
     * Source columns of the workflow's date / "Aktuelle Zeit" answer fields, used
     * as the printed signature date in the PDF (storage column => label).
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_workflow', target: 'fields.pdfSignatureDate.options')]
    public function getSignatureDateOptions(DataContainer $dc): array
    {
        $workflow = $this->getWorkflow($dc);

        if (null === $workflow) {
            return [];
        }

        $fields = [];

        foreach ($workflow->getQuestions() as $question) {
            if (!\in_array((string) $question->type, ['date', 'currentTime'], true)) {
                continue;
            }

            $field = trim((string) $question->storageField);

            if ('' !== $field) {
                $fields[$field] = $field.' ('.(string) $question->label.')';
            }
        }

        return $fields;
    }

    /**
     * Pre-selects the first available master ("Briefpapier") on a new workflow.
     */
    #[AsCallback(table: 'tl_workflow', target: 'fields.master.load')]
    public function preselectMaster(mixed $value): mixed
    {
        if ((int) $value > 0) {
            return $value;
        }

        $master = MasterModel::findAll(['order' => 'id', 'limit' => 1]);

        return null !== $master ? (int) $master->id : $value;
    }

    /**
     * Offers the available master (letterhead) templates ("pdf_master*").
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_workflow_master', target: 'fields.masterTemplate.options')]
    public function getMasterTemplateOptions(): array
    {
        return Controller::getTemplateGroup('pdf_master');
    }

    /**
     * Normalises a master's "PDF-Variablen" on save (PdfVarsWidget posts the same
     * key/value list as the old MCW): drops rows with an empty key and decodes
     * HTML entities in keys/values, so special characters and ##tokens## are stored
     * literally (the old MCW columns used decodeEntities for this).
     *
     * @param mixed $value
     */
    #[AsCallback(table: 'tl_workflow_master', target: 'fields.pdfData.save')]
    public function cleanPdfData(mixed $value): mixed
    {
        $clean = [];

        foreach (StringUtil::deserialize($value, true) as $pair) {
            $key = trim((string) ($pair['key'] ?? ''));

            if ('' === $key) {
                continue;
            }

            $clean[] = [
                'key'   => html_entity_decode($key, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'value' => html_entity_decode((string) ($pair['value'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        return serialize($clean);
    }

    private function getWorkflow(DataContainer $dc): ?WorkflowModel
    {
        if (!$dc->id) {
            return null;
        }

        return WorkflowModel::findByPk((int) $dc->id);
    }

    /**
     * Resolves the parent workflow id of the answer field currently being edited
     * or created. On edit "id" is the question; on create "pid" is either the
     * parent workflow (PASTE_INTO) or a sibling question (PASTE_AFTER, mode 1).
     */
    private function resolveQuestionWorkflowId(DataContainer $dc): int
    {
        if (isset($dc->activeRecord->pid) && (int) $dc->activeRecord->pid > 0) {
            return (int) $dc->activeRecord->pid;
        }

        if ($dc->id && 'create' !== Input::get('act')) {
            $question = QuestionModel::findByPk((int) $dc->id);

            if (null !== $question) {
                return (int) $question->pid;
            }
        }

        $pid = (int) Input::get('pid');

        if ($pid < 1) {
            return 0;
        }

        if (1 === (int) Input::get('mode')) {
            $sibling = QuestionModel::findByPk($pid);

            return null !== $sibling ? (int) $sibling->pid : 0;
        }

        return $pid;
    }
}
