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
    public function __construct(
        private readonly SpreadsheetInspector $inspector,
        private readonly QuestionParentResolver $questionParent,
    ) {
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
        $workflow = WorkflowModel::findByPk($this->questionParent->resolve($dc));

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
     * Restricted to columns the current source file actually has. The answer fields alone
     * are not enough of a check: they are copied along with the workflow while the source
     * file is not (doNotCopy), so a copy would still offer them and the stored value would
     * look perfectly valid — no "Unbekannte Option", unlike every other source-dependent
     * picker in the mask. A storage column that is not a header is broken anyway; the
     * validator already reports it as such.
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

        $headers = $this->inspector->getHeaderOptions($workflow);
        $fields = [];

        foreach ($workflow->getQuestions() as $question) {
            if (!\in_array((string) $question->type, ['date', 'currentTime'], true)) {
                continue;
            }

            $field = trim((string) $question->storageField);

            if ('' !== $field && isset($headers[$field])) {
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

}
