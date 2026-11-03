<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Psimandl\WorkflowBundle\Service\EntryReset;
use Psimandl\WorkflowBundle\Service\WorkflowLock;
use Symfony\Component\Routing\RouterInterface;

/**
 * Renders the "reset all participants" button in the workflow edit mask
 * (input_field_callback pseudo-field). It takes the whole run back to "imported" (see
 * EntryReset) and is the documented way out of the edit lock when a change has to take effect
 * in the current run; for the next run the intended path is a copy of the workflow. Resolved
 * via System::importStatic, so this must be a public service.
 */
class ResetButtonListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntryReset $entryReset,
        private readonly WorkflowLock $lock,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
    ) {
    }

    public function renderResetButton(DataContainer $dc): string
    {
        $id = (int) ($dc->id ?? 0);

        if ($id <= 0) {
            return $this->hint('Bitte den Workflow zuerst speichern.');
        }

        $affected = $this->entryReset->countResettable($id);

        if (0 === $affected) {
            return $this->hint('Es wurde noch nichts versendet oder beantwortet – es gibt nichts zurückzusetzen.');
        }

        $url = $this->router->generate('workflow_reset_entries', ['id' => $id])
            .'?rt='.$this->csrfTokenManager->getDefaultTokenValue();

        $answered = $this->lock->answeredCount($id);

        $confirm = sprintf(
            'Wirklich %d Teilnehmer auf „importiert“ zurücksetzen?%s Versandstatus und Versandfehler '
            .'werden gelöscht. Das lässt sich nicht rückgängig machen.',
            $affected,
            $answered > 0
                ? sprintf(' Die %d erfassten Antworten verlieren ihre Gültigkeit und die bereits ausgestellten Dokumente werden ungültig.', $answered)
                : '',
        );

        return '<div class="widget" style="clear:both">'
            .'<a href="'.htmlspecialchars($url, ENT_QUOTES).'" class="tl_submit" style="text-decoration:none"'
            .' onclick="return confirm(\''.htmlspecialchars(addslashes($confirm), ENT_QUOTES).'\')">'
            .htmlspecialchars(sprintf('%d Teilnehmer zurücksetzen', $affected), ENT_QUOTES)
            .'</a>'
            .'<p class="tl_help" style="margin-top:.5em">'
            .'Setzt alle Teilnehmer auf „importiert“ zurück und verwirft Antwortzeitpunkt, '
            .'Bestätigungsstatus, Versandzeitpunkt und Versandfehler. <strong>Erhalten bleiben:</strong> '
            .'die erfassten Daten (sie füllen das Formular vor), die Links der Teilnehmer, die bisherigen '
            .'Dokumente – sie werden beim erneuten Absenden überschrieben – und <strong>gesperrte '
            .'Adressen</strong> (Bounce oder vom Mailserver abgelehnt): Sie werden erst wieder '
            .'angeschrieben, wenn die Adresse am Eintrag geändert wird. Danach sind die gesperrten '
            .'Quell-Einstellungen wieder änderbar; anschließend ist ein erneuter Import nötig, um die '
            .'Originaldaten zu laden.'
            .'</p></div>';
    }

    private function hint(string $text): string
    {
        return '<div class="widget"><p class="tl_help">'.htmlspecialchars($text, ENT_QUOTES).'</p></div>';
    }
}
