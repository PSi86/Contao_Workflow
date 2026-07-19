<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\LinkGenerator;

/**
 * Shows the entry's real, individual form link in the back end. The URL is not a fixed
 * "…/workflow/<token>" – it is the configured form page's own URL plus the token
 * (alias, domain and URL suffix all come from that page). Resolving it here removes the
 * guesswork that previously led to 404s when the form page had a different alias.
 *
 * Rendered as its own read-only input rather than as the token field's help text: in a run of
 * prose the URL cannot be selected cleanly (a double click grabs the surrounding words, and
 * the line may wrap mid-URL). An input holds exactly the URL, selects itself on click and is
 * copied by the button next to it. Resolved via System::importStatic, so this must be a
 * public service.
 */
class EntryFormLinkListener
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LinkGenerator $linkGenerator,
    ) {
    }

    public function renderFormLink(DataContainer $dc): string
    {
        $entryId = (int) ($dc->id ?? 0);

        if ($entryId <= 0) {
            return $this->note('Der Link steht zur Verfügung, sobald der Eintrag gespeichert ist.');
        }

        $this->framework->initialize();

        $entry = EntryModel::findByPk($entryId);
        $workflow = null !== $entry ? WorkflowModel::findByPk((int) $entry->pid) : null;

        if (null === $entry || null === $workflow) {
            return $this->note('Der Eintrag gehört zu keinem gültigen Workflow.');
        }

        try {
            $url = $this->linkGenerator->getFormLink($workflow, $entry);
        } catch (\Throwable) {
            return $this->note(
                'Am Workflow ist noch keine (gültige) Formularseite konfiguriert – ohne sie gibt es '
                .'keinen Formular-Link.',
            );
        }

        $id = 'ctrl_wfFormLink';

        // Clipboard API where available (needs a secure context), otherwise the old
        // execCommand path – the input is selected either way, so a manual copy always works.
        $copy = "var i=document.getElementById('".$id."');i.focus();i.select();"
            ."i.setSelectionRange(0,i.value.length);"
            ."if(navigator.clipboard){navigator.clipboard.writeText(i.value)}else{document.execCommand('copy')}"
            ."var b=this;b.textContent='Kopiert';setTimeout(function(){b.textContent='Kopieren'},1500);return false";

        return '<div class="widget" style="clear:both">'
            .'<h3><label for="'.$id.'">Formular-Link</label></h3>'
            .'<div style="display:flex;gap:.5em;align-items:center">'
            .'<input type="text" id="'.$id.'" class="tl_text" readonly'
            .' value="'.htmlspecialchars($url, ENT_QUOTES).'"'
            .' onclick="this.select()" style="flex:1;min-width:0">'
            .'<button type="button" class="tl_submit" onclick="'.htmlspecialchars($copy, ENT_QUOTES).'">Kopieren</button>'
            .'</div>'
            .'<p class="tl_help" style="margin-top:.5em">Persönlicher Link dieses Teilnehmers. '
            .'Ein Klick ins Feld markiert ihn vollständig.</p>'
            .'</div>';
    }

    private function note(string $text): string
    {
        return '<div class="widget" style="clear:both">'
            .'<h3>Formular-Link</h3>'
            .'<p class="tl_help">'.htmlspecialchars($text, ENT_QUOTES).'</p>'
            .'</div>';
    }
}
