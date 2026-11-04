<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SourceColumnCheck;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;

/**
 * Keeps the source file exchangeable while participants are answering, but only against a
 * file with the identical column set – the rule itself lives in SourceColumnCheck, which the
 * upload paths share (3.6.2).
 *
 * Runs after SourceFileSelectionListener (priority 0 vs. 20/10), so an upload in the edit mask
 * is already the value checked here.
 */
class SourceFileGuardListener
{
    public function __construct(
        private readonly SourceColumnCheck $columnCheck,
        private readonly SpreadsheetInspector $inspector,
    ) {
    }

    #[AsCallback(table: 'tl_workflow', target: 'fields.sourceFile.save')]
    public function assertSameColumns(mixed $value, DataContainer $dc): mixed
    {
        $id = (int) ($dc->id ?? 0);
        $workflow = $id > 0 ? WorkflowModel::findByPk($id) : null;

        if (null === $workflow) {
            return $value;
        }

        $candidate = clone $workflow;
        $candidate->sourceFile = $value;

        $this->columnCheck->assertSameColumns($workflow, $this->inspector->resolvePath($candidate));

        return $value;
    }
}
