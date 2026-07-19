<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\LinkGenerator;

/**
 * Shows the entry's real, individual form link in the back end. The URL is not a fixed
 * "…/workflow/<token>" – it is the configured form page's own URL plus the token
 * (alias, domain and URL suffix all come from that page). Resolving it here removes the
 * guesswork that previously led to 404s when the form page had a different alias.
 *
 * The link sits in the token field's help text, but as its own element rather than as running
 * prose: "user-select: all" makes a single click select exactly the URL and nothing around it,
 * and the click copies it to the clipboard on top of that. As plain text it could neither be
 * selected cleanly (a double click grabs the surrounding words) nor survive a line wrap.
 */
class EntryFormLinkListener
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly LinkGenerator $linkGenerator,
    ) {
    }

    /**
     * load_callback for tl_workflow_entry.token: replaces the static field help with the
     * actual form link (or a clear note when no valid form page is configured yet).
     */
    #[AsCallback(table: 'tl_workflow_entry', target: 'fields.token.load')]
    public function showFormLink(mixed $value, DataContainer $dc): mixed
    {
        $title = (string) ($GLOBALS['TL_LANG']['tl_workflow_entry']['token'][0] ?? 'Token');
        $help = $this->buildHelp((int) ($dc->id ?? 0));
        $field = &$GLOBALS['TL_DCA']['tl_workflow_entry']['fields']['token'];

        $field['label'] = [$title, $help];

        // Contao renders every field help as <p class="tl_help tl_tip">, and tl_tip clamps it
        // to a single 15px line with overflow:hidden – a multi-line help is simply cut off.
        // The class cannot be set on that <p>, but it can be set on the wrapper via tl_class,
        // which is where the stylesheet lifts the clamp for this one field.
        if (str_contains($help, 'wf-copylink')) {
            $eval = &$field['eval'];
            $eval['tl_class'] = trim(((string) ($eval['tl_class'] ?? '')).' tw-linkhelp');
            unset($eval);
        }

        unset($field);

        return $value;
    }

    private function buildHelp(int $entryId): string
    {
        $fallback = 'Individueller Schlüssel für den persönlichen Formular-Link.';

        if ($entryId <= 0) {
            return $fallback;
        }

        $this->framework->initialize();

        $entry = EntryModel::findByPk($entryId);
        $workflow = null !== $entry ? WorkflowModel::findByPk((int) $entry->pid) : null;

        if (null === $entry || null === $workflow) {
            return $fallback;
        }

        try {
            $url = $this->linkGenerator->getFormLink($workflow, $entry);
        } catch (\Throwable) {
            return 'Individueller Schlüssel. Am Workflow ist noch keine (gültige) Formularseite konfiguriert – '
                .'ohne sie gibt es keinen Formular-Link.';
        }

        $GLOBALS['TL_CSS']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.css';

        // Clipboard API where available (it needs a secure context); otherwise select the
        // element and fall back to execCommand. Either way the URL ends up selected, so a
        // manual copy always works too.
        $copy = 'var t=this.textContent.trim();'
            .'var r=document.createRange();r.selectNodeContents(this);'
            .'var s=window.getSelection();s.removeAllRanges();s.addRange(r);'
            ."if(navigator.clipboard){navigator.clipboard.writeText(t)}else{document.execCommand('copy')}"
            ."var e=this;e.classList.add('wf-copied');"
            ."setTimeout(function(){e.classList.remove('wf-copied')},1500)";

        return 'Formular-Link – klicken zum Kopieren:<br>'
            .'<span class="wf-copylink" title="Klicken kopiert den Link in die Zwischenablage"'
            .' onclick="'.StringUtil::specialchars($copy).'">'
            .StringUtil::specialchars($url)
            .'</span>';
    }
}
