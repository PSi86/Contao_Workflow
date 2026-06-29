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
use Symfony\Component\Console\Input\InputOption;
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
        $this
            ->addArgument('workflow', InputArgument::REQUIRED, 'The tl_workflow ID to import.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Import even if the source file is unchanged.')
        ;
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
            $result = $this->importer->import($workflow, (bool) $input->getOption('force'));
        } catch (\Throwable $e) {
            $io->error('Import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ($result['skipped']) {
            $io->warning('Source file unchanged – import skipped (use --force to override).');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Workflow "%s": %d new, %d updated (total %d).',
            $workflow->title,
            $result['inserted'],
            $result['updated'],
            $result['total'],
        ));

        return Command::SUCCESS;
    }
}
