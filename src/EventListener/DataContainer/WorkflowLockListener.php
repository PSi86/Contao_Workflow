<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Environment;
use Contao\Input;
use Contao\Message;
use Psimandl\WorkflowBundle\Service\WorkflowLock;

/**
 * Freezes the settings that define what the stored answers mean, once participants have
 * answered. See WorkflowLock for the rationale and for how the lock is released again.
 *
 * Enforcement is Contao's own eval.readonly: it clears the widget's submitInput(), and
 * DataContainer::row() then skips reading the field from the request entirely
 * (DataContainer.php, "Validate and save the field"). A crafted POST therefore cannot change
 * a locked field either — no additional save_callback is needed. Adding and deleting answer
 * fields is blocked the same way, through notCreatable/notDeletable, which DC_Table turns
 * into an AccessDeniedException.
 */
class WorkflowLockListener
{
    /**
     * Settings that decide which source column an answer belongs to. Changing any of them
     * after an answer exists rewrites or orphans that answer on the next import.
     *
     * sourceFile is deliberately NOT among them: swapping in a fresh export of the same
     * report is the normal way to add or correct participants mid-run. It is guarded by a
     * column-identity check instead (see SourceFileGuardListener), which keeps exactly the
     * property these locked fields rely on.
     */
    private const LOCKED_SOURCE_FIELDS = ['sourceSheet', 'headerRow', 'emailField'];

    public function __construct(
        private readonly WorkflowLock $lock,
        private readonly QuestionParentResolver $questionParent,
    ) {
    }

    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function lockSourceSettings(DataContainer $dc): void
    {
        $id = (int) ($dc->id ?? 0);

        if ($id < 1 || !$this->lock->isLocked($id)) {
            return;
        }

        foreach (self::LOCKED_SOURCE_FIELDS as $field) {
            $this->freeze('tl_workflow', $field);
        }

        if ($this->isEditMask()) {
            Message::addInfo($this->notice($this->lock->answeredCount($id)));
        }
    }

    #[AsCallback(table: 'tl_workflow_question', target: 'config.onload')]
    public function lockAnswerFields(DataContainer $dc): void
    {
        if (!$this->lock->isLocked($this->questionParent->resolve($dc))) {
            return;
        }

        // The storage field is the key the answers are filed under; adding a field would
        // leave the answered entries without a value for it, deleting one would drop their
        // answer on the next import. The wording, the document text and the options stay
        // editable — they do not change what the stored data means.
        $this->freeze('tl_workflow_question', 'storageField');

        $config = &$GLOBALS['TL_DCA']['tl_workflow_question']['config'];
        $config['notCreatable'] = true;
        $config['notDeletable'] = true;
        unset($config);
    }

    /**
     * Disables a field and flags it visually.
     *
     * "disabled" rather than "readonly": both clear the widget's submitInput() and therefore
     * stop the value from being written, but SelectMenu and FileTree contain no readonly
     * handling at all, so a read-only select stayed fully operable and silently discarded the
     * change on save. "disabled" is emitted as a real HTML attribute by every widget that
     * renders getAttributes(), so the field is inert in the browser as well.
     */
    private function freeze(string $table, string $field): void
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'][$field])) {
            return;
        }

        $eval = &$GLOBALS['TL_DCA'][$table]['fields'][$field]['eval'];
        $eval['disabled'] = true;
        $eval['tl_class'] = trim(((string) ($eval['tl_class'] ?? '')).' tw-locked');

        // A disabled field must not keep auto-submitting the mask on change.
        unset($eval['submitOnChange']);
        unset($eval);

        $GLOBALS['TL_CSS']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.css';
    }

    private function notice(int $answered): string
    {
        return sprintf(
            'Für diesen Workflow liegen bereits <strong>%d Antwort(en)</strong> vor. '
            .'Tabellenblatt, Kopfzeile, E-Mail-Spalte und die Speicherspalten der Formularfelder '
            .'sind deshalb gesperrt, ebenso das Anlegen und Löschen von Formularfeldern – eine '
            .'Änderung würde die bereits erfassten Antworten zerstören oder von den Daten '
            .'trennen, auf deren Grundlage bereits Dokumente ausgestellt wurden.<br>'
            .'Die <strong>Quelldatei lässt sich weiterhin austauschen</strong>, solange die neue '
            .'Datei exakt dieselben Spalten enthält – so lassen sich Teilnehmer nachmelden oder '
            .'Daten korrigieren. Bereits beantwortete Teilnehmer bleiben dabei unverändert.<br>'
            .'Für den <strong>nächsten Durchlauf</strong> eine Kopie dieses Workflows anlegen und '
            .'dort anpassen. Soll eine gesperrte Einstellung <strong>noch in diesem Durchlauf</strong> '
            .'geändert werden, müssen zuvor alle Teilnehmer zurückgesetzt werden (Abschnitt '
            .'„Zurücksetzen“ am Ende dieser Seite) – dabei werden alle bisherigen Antworten und die '
            .'bereits ausgestellten Dokumente ungültig.',
            $answered,
        );
    }

    /**
     * Only annotate the rendered edit form, not the POST that saves it – otherwise the notice
     * is added twice per save. Mirrors the gating in WorkflowIntegrityListener.
     */
    private function isEditMask(): bool
    {
        if (Environment::get('isAjaxRequest')) {
            return false;
        }

        return \in_array((string) Input::get('act'), ['edit', 'editAll'], true)
            && '' === (string) Input::post('FORM_SUBMIT');
    }
}
