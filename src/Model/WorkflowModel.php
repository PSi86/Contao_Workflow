<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Model;

use Contao\Model;
use Contao\Model\Collection;
use Contao\StringUtil;

/**
 * Reads and writes tl_workflow (a process definition).
 *
 * @property int    $id
 * @property int    $tstamp
 * @property string $title
 * @property string $steps         Serialized ordered list of step labels.
 * @property string $sourceFile    UUID of the uploaded source CSV/XLSX file.
 * @property string $sourceSheet   Worksheet name to read (empty = active sheet).
 * @property int    $headerRow     1-based row holding the column headers.
 * @property string $emailField    Header name of the e-mail column.
 * @property string $requireSignature Whether the form requires a signature ("1"/"").
 * @property int    $formPage        Page id hosting the form module.
 * @property string $pdfBodyType     "letter" (backend text) or "template" (body template file).
 * @property string $pdfTitle        Letter heading (letter mode), supports ##tokens##.
 * @property string $pdfSignatureDate Data column whose value is printed as the signature date.
 * @property string $pdfSignatureLocation Data column whose value is printed as the signature place.
 * @property string $pdfBodyTemplate Body template name (template mode), e.g. pdf_body_verzicht.
 * @property int    $master        tl_workflow_master id (letterhead: template + logo + variables).
 * @property string $sourceHash    Checksum of the last imported source file.
 * @property int    $ncInvite      Notification id for the invitation mail.
 * @property int    $ncReminder    Notification id for the reminder mail.
 * @property int    $ncResult      Notification id for the result mail (with PDF).
 * @property string $published
 */
class WorkflowModel extends Model
{
    protected static $strTable = 'tl_workflow';

    /**
     * Returns the ordered list of step labels for this workflow.
     *
     * @return array<int, string>
     */
    public function getSteps(): array
    {
        $steps = array_values(array_filter(
            array_map('trim', StringUtil::deserialize($this->steps, true)),
            static fn ($label) => '' !== $label,
        ));

        return $steps ?: ['Importiert', 'Eingeladen', 'Beantwortet'];
    }

    /**
     * Index of the final step. Reaching this status means "answered".
     */
    public function getFinalStatus(): int
    {
        return max(0, \count($this->getSteps()) - 1);
    }

    public function isSignatureRequired(): bool
    {
        return '1' === (string) $this->requireSignature;
    }

    /**
     * Letterhead variables of the configured master (##var_*## tokens);
     * empty when no master is set.
     *
     * @return array<string, string>
     */
    public function getMasterVars(): array
    {
        if (!$this->master) {
            return [];
        }

        $master = MasterModel::findByPk((int) $this->master);

        return null !== $master ? $master->getPdfData() : [];
    }

    /**
     * Configured answer fields, in display order.
     *
     * @return array<int, QuestionModel>
     */
    public function getQuestions(): array
    {
        $questions = QuestionModel::findByWorkflow((int) $this->id);

        if (null === $questions) {
            return [];
        }

        $out = [];
        foreach ($questions as $question) {
            $out[] = $question;
        }

        return $out;
    }

    /**
     * Configured PDF rules, in priority (sorting) order.
     *
     * @return array<int, RuleModel>
     */
    public function getRules(): array
    {
        $rules = RuleModel::findByWorkflow((int) $this->id);

        if (null === $rules) {
            return [];
        }

        $out = [];
        foreach ($rules as $rule) {
            $out[] = $rule;
        }

        return $out;
    }

    /**
     * Distinct source columns the answers are written into (in question order).
     *
     * @return array<int, string>
     */
    public function getStorageFields(): array
    {
        $fields = [];

        foreach ($this->getQuestions() as $question) {
            $field = trim((string) $question->storageField);

            if ('' !== $field && !\in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
