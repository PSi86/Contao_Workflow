<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;

/**
 * Surfaces an incomplete/not-runnable workflow in the edit mask: an info box with
 * the concrete problems, red-outlined source-dependent fields, and a warning on
 * save. Typical after a copy, before a new source file has been loaded – saving
 * stays possible, but executing the workflow is blocked elsewhere.
 */
class WorkflowIntegrityListener
{
    public function __construct(private readonly WorkflowValidator $validator)
    {
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
}
