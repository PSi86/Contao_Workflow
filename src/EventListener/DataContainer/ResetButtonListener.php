<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Psimandl\WorkflowBundle\Service\WorkflowLock;
use Symfony\Component\Routing\RouterInterface;

/**
 * Renders the "reset all participants" button in the workflow edit mask
 * (input_field_callback pseudo-field). It is the documented way out of the edit lock when a
 * change has to take effect in the current run; for the next run the intended path is a copy
 * of the workflow. Resolved via System::importStatic, so this must be a public service.
 */
class ResetButtonListener
{
    public function __construct(
        private readonly RouterInterface $router,
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

        $answered = $this->lock->answeredCount($id);

        if (0 === $answered) {
            return $this->hint('Es liegen keine beantworteten Einträge vor – es gibt nichts zurückzusetzen.');
        }

        $url = $this->router->generate('workflow_reset_entries', ['id' => $id])
            .'?rt='.$this->csrfTokenManager->getDefaultTokenValue();

        $confirm = sprintf(
            'Wirklich alle %d beantworteten Einträge zurücksetzen? Die erfassten Antworten verlieren '
            .'ihre Gültigkeit und die bereits ausgestellten Dokumente werden ungültig. '
            .'Das lässt sich nicht rückgängig machen.',
            $answered,
        );

        return '<div class="widget" style="clear:both">'
            .'<a href="'.htmlspecialchars($url, ENT_QUOTES).'" class="tl_submit" style="text-decoration:none"'
            .' onclick="return confirm(\''.htmlspecialchars(addslashes($confirm), ENT_QUOTES).'\')">'
            .htmlspecialchars(sprintf('Alle %d Teilnehmer zurücksetzen', $answered), ENT_QUOTES)
            .'</a>'
            .'<p class="tl_help" style="margin-top:.5em">'
            .'Setzt den Schritt aller Teilnehmer auf „importiert" zurück und verwirft Antwortzeitpunkt '
            .'sowie Bestätigungsstatus. <strong>Erhalten bleiben:</strong> die erfassten Daten (sie füllen '
            .'das Formular vor), die Links der Teilnehmer und die bisherigen Dokumente – letztere werden '
            .'beim erneuten Absenden überschrieben. Danach sind die gesperrten Quell-Einstellungen wieder '
            .'änderbar; anschließend ist ein erneuter Import nötig, um die Originaldaten zu laden.'
            .'</p></div>';
    }

    private function hint(string $text): string
    {
        return '<div class="widget"><p class="tl_help">'.htmlspecialchars($text, ENT_QUOTES).'</p></div>';
    }
}
