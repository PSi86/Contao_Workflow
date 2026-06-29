<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\FilesModel;
use Contao\Message;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\PlaceholderResolver;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;

/**
 * Surfaces an incomplete/not-runnable workflow in the edit mask: an info box with
 * the concrete problems, red-outlined source-dependent fields, and a warning on
 * save. Typical after a copy, before a new source file has been loaded – saving
 * stays possible, but executing the workflow is blocked elsewhere.
 *
 * Also warns when the (PII-laden) source spreadsheet sits in a publicly served
 * folder of the Contao file manager, where it would be downloadable without login.
 */
class WorkflowIntegrityListener
{
    /** Cap for the prefill sample so huge workflows do not slow down the edit mask. */
    private const PREFILL_CHECK_LIMIT = 500;

    public function __construct(
        private readonly WorkflowValidator $validator,
        private readonly PlaceholderResolver $placeholders,
        private readonly SpreadsheetInspector $inspector,
        private readonly string $projectDir,
    ) {
    }

    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function flagIncomplete(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $workflow = WorkflowModel::findByPk((int) $dc->id);

        if (null === $workflow) {
            return;
        }

        $problems = $this->validator->getProblems($workflow);

        if ([] === $problems) {
            return;
        }

        $items = '';

        foreach ($problems as $problem) {
            $items .= '<li>'.StringUtil::specialchars($problem).'</li>';
        }

        Message::addInfo(
            'Dieser Workflow ist noch nicht vollständig konfiguriert und kann nicht ausgeführt werden:<ul>'
            .$items
            .'</ul>Die rot umrandeten Felder beziehen sich auf die (noch fehlende) Quelldatei. '
            .'Speichern ist möglich; Import, Versand und Export bleiben gesperrt, bis eine gültige Quelldatei geladen ist.',
        );

        foreach ($this->validator->orphanedFields($workflow) as $field) {
            $eval = &$GLOBALS['TL_DCA']['tl_workflow']['fields'][$field]['eval'];
            $eval['tl_class'] = trim(((string) ($eval['tl_class'] ?? '')).' tw-invalid');
        }

        $GLOBALS['TL_CSS']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.css';
    }

    #[AsCallback(table: 'tl_workflow', target: 'config.onsubmit')]
    public function warnOnSave(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $workflow = WorkflowModel::findByPk((int) $dc->id);

        if (null !== $workflow && !$this->validator->isRunnable($workflow)) {
            // Contao\Message has no addWarning(); addInfo is the right notice level.
            Message::addInfo(
                'Hinweis: Der Workflow wurde gespeichert, ist aber noch nicht ausführbar. '
                .'Bis eine gültige Quelldatei geladen ist, sind Import, Versand und Export gesperrt.',
            );
        }
    }

    /**
     * Warns when several source columns normalize to the same placeholder slug,
     * so only the first is reachable via its ##data_<slug>## token (the rest are
     * ignored, their values still imported/exported). Mirrors the warning shown
     * at import time, but proactively on the edit page so the ambiguity is visible
     * before the first import. No source file = no headers = nothing to warn about.
     */
    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function warnSlugCollisions(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $workflow = WorkflowModel::findByPk((int) $dc->id);

        if (null === $workflow) {
            return;
        }

        foreach ($this->placeholders->slugCollisions($this->inspector->getHeaders($workflow)) as $slug => $names) {
            Message::addInfo(sprintf(
                'Hinweis: Die Spalten „%s" der Quelldatei ergeben denselben Platzhalter „##data_%s##". '
                .'Nur „%s" ist darüber erreichbar, die übrigen werden ignoriert (ihre Werte bleiben '
                .'gespeichert und exportierbar, nur nicht per Platzhalter adressierbar). Bitte die '
                .'betroffenen Spalten in der Quelldatei eindeutiger benennen.',
                StringUtil::specialchars(implode('", „', $names)),
                $slug,
                StringUtil::specialchars($names[0]),
            ));
        }
    }

    /**
     * Non-blocking warnings for the statement ("Textbaustein") layer:
     * - ##text_*## tokens in the PDF heading or a rule body that match no
     *   answer field (typo or removed field would render as literal text),
     * - prefill values in the data that match no option of a choice question
     *   (the form would silently start empty for those entries).
     */
    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function warnStatementAndPrefillIssues(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $workflow = WorkflowModel::findByPk((int) $dc->id);

        if (null === $workflow) {
            return;
        }

        $questions = $workflow->getQuestions();

        foreach ($this->unknownStatementTokens($workflow, $questions) as $token) {
            Message::addInfo(sprintf(
                'Hinweis: Der Platzhalter „##%s##" passt zu keinem Antwortfeld dieses Workflows '
                .'und würde im PDF unersetzt stehen bleiben.',
                StringUtil::specialchars($token),
            ));
        }

        foreach ($this->prefillMismatches($workflow, $questions) as $message) {
            Message::addInfo($message);
        }
    }

    /**
     * @param array<int, QuestionModel> $questions
     *
     * @return array<int, string>
     */
    private function unknownStatementTokens(WorkflowModel $workflow, array $questions): array
    {
        $valid = ['text_all'];

        foreach ($questions as $question) {
            $field = trim((string) $question->storageField);

            if ('' !== $field) {
                $valid[] = 'text_'.$this->placeholders->normalize($field);
            }
        }

        $texts = [(string) $workflow->pdfTitle];

        foreach ($workflow->getRules() as $rule) {
            $texts[] = (string) $rule->getPdfBody();
        }

        preg_match_all('/##(text_[a-z0-9_]+)##/i', implode("\n", $texts), $matches);

        return array_values(array_diff(array_unique(array_map('strtolower', $matches[1])), $valid));
    }

    /**
     * @param array<int, QuestionModel> $questions
     *
     * @return array<int, string>
     */
    private function prefillMismatches(WorkflowModel $workflow, array $questions): array
    {
        $checks = [];

        foreach ($questions as $question) {
            if ($question->isPrefilled() && $question->hasOptions() && '' !== trim((string) $question->storageField)) {
                $checks[] = $question;
            }
        }

        if ([] === $checks) {
            return [];
        }

        $entries = EntryModel::findBy('pid', (int) $workflow->id, ['limit' => self::PREFILL_CHECK_LIMIT]);

        if (null === $entries) {
            return [];
        }

        $samples = [];

        foreach ($entries as $entry) {
            $data = $entry->getData();

            foreach ($checks as $question) {
                $storage = trim((string) $question->storageField);
                $raw = trim((string) ($data[$storage] ?? ''));

                if ('' === $raw || isset($samples[$storage])) {
                    continue;
                }

                $values = $question->isMultiple() ? (preg_split('/\s*,\s*/', $raw) ?: []) : [$raw];

                foreach ($values as $value) {
                    if ('' !== $value && !$this->matchesOption($question, $value)) {
                        $samples[$storage] = [$question, $value];
                        break;
                    }
                }
            }
        }

        $messages = [];

        foreach ($samples as $storage => [$question, $value]) {
            $messages[] = sprintf(
                'Hinweis: Die Spalte „%s" enthält Werte, die zu keiner Option des Antwortfelds „%s" passen '
                .'(z. B. „%s"). Diese Felder starten im Formular ohne Vorbelegung.',
                StringUtil::specialchars($storage),
                StringUtil::specialchars((string) $question->label),
                StringUtil::specialchars($value),
            );
        }

        return $messages;
    }

    /**
     * Mirrors the front end's prefill matching: exact first, then
     * trimmed/case-insensitive.
     */
    private function matchesOption(QuestionModel $question, string $value): bool
    {
        foreach ($question->getAllowedValues() as $candidate) {
            if ($candidate === $value || 0 === strcasecmp(trim($candidate), $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Warns when the source spreadsheet is stored in a publicly served folder of
     * the file manager: it (and all the personal data it contains) would then be
     * directly downloadable without a login. Source files belong in a protected
     * folder – in Contao they are protected by default unless a folder is published.
     */
    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function warnPublicSourceFile(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $workflow = WorkflowModel::findByPk((int) $dc->id);

        if (null === $workflow || !$this->sourceFileIsPublic($workflow)) {
            return;
        }

        Message::addError(
            'Datenschutz-Hinweis: Die Quelldatei dieses Workflows liegt in einem öffentlichen '
            .'Ordner der Dateiverwaltung und ist damit ohne Login direkt herunterladbar – '
            .'inklusive aller personenbezogenen Daten. Bitte den Ordner in der Dateiverwaltung '
            .'schützen (Kontextmenü „Schützen") oder die Quelldatei in einen geschützten Ordner verschieben.',
        );
    }

    /**
     * A Contao folder is web-accessible when it (or an ancestor) carries a
     * ".public" marker file; a file inside is then directly downloadable. Walks
     * the source file's folder path upwards and checks for that marker.
     */
    private function sourceFileIsPublic(WorkflowModel $workflow): bool
    {
        if (!$workflow->sourceFile) {
            return false;
        }

        $file = FilesModel::findByUuid($workflow->sourceFile);

        if (null === $file) {
            return false;
        }

        $parts = explode('/', \dirname((string) $file->path));

        while ([] !== $parts) {
            if (is_file($this->projectDir.'/'.implode('/', $parts).'/.public')) {
                return true;
            }

            array_pop($parts);
        }

        return false;
    }
}
