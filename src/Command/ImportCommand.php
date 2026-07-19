<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SpreadsheetImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'workflow:import',
    description: 'Imports the configured source file of a workflow into tl_workflow_entry.',
)]
class ImportCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SpreadsheetImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('workflow', InputArgument::REQUIRED, 'The tl_workflow ID to import.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->framework->initialize();

        $id = (int) $input->getArgument('workflow');
        $workflow = WorkflowModel::findByPk($id);

        if (null === $workflow) {
            $io->error(sprintf('Workflow %d not found.', $id));

            return Command::FAILURE;
        }

        try {
            $result = $this->importer->import($workflow);
        } catch (\Throwable $e) {
            $io->error('Import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Workflow "%s": %d new, %d updated, %d left untouched (already answered) – total %d.',
            $workflow->title,
            $result['inserted'],
            $result['updated'],
            $result['protected'],
            $result['total'],
        ));

        $this->warnCollisions($io, $result['collisions']);

        return Command::SUCCESS;
    }

    /**
     * Warns about source columns that normalize to the same placeholder slug:
     * only the first keeps the token, the rest are not addressable via ##data_*##.
     *
     * @param array<string, array<int, string>> $collisions slug => colliding names
     */
    private function warnCollisions(SymfonyStyle $io, array $collisions): void
    {
        if ([] === $collisions) {
            return;
        }

        $lines = [];

        foreach ($collisions as $slug => $names) {
            $lines[] = sprintf(
                '##data_%s##: keeps "%s", ignores "%s"',
                $slug,
                $names[0],
                implode('", "', \array_slice($names, 1)),
            );
        }

        $io->warning(
            "Ambiguous source columns (same placeholder slug). The first keeps the token, "
            ."the rest are ignored – rename them in the source file to disambiguate:\n"
            .implode("\n", $lines),
        );
    }
}
