<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\FilesModel;
use Contao\Message;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
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
    public function __construct(
        private readonly WorkflowValidator $validator,
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
