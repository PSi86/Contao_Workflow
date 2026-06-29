<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
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
        $GLOBALS['TL_DCA']['tl_workflow_entry']['fields']['token']['label'] = [$title, $this->buildHelp((int) ($dc->id ?? 0))];

        return $value;
    }

    private function buildHelp(int $entryId): string
    {
        if ($entryId <= 0) {
            return 'Individueller Schlüssel für den persönlichen Formular-Link.';
        }

        $this->framework->initialize();

        $entry = EntryModel::findByPk($entryId);
        $workflow = null !== $entry ? WorkflowModel::findByPk((int) $entry->pid) : null;

        if (null === $entry || null === $workflow) {
            return 'Individueller Schlüssel für den persönlichen Formular-Link.';
        }

        try {
            return 'Formular-Link (zum Kopieren): '.$this->linkGenerator->getFormLink($workflow, $entry);
        } catch (\Throwable) {
            return 'Individueller Schlüssel. Am Workflow ist noch keine (gültige) Formularseite konfiguriert – '
                .'ohne sie gibt es keinen Formular-Link.';
        }
    }
}
