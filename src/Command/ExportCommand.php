<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SpreadsheetExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'workflow:export',
    description: 'Exports a workflow (source columns refilled with current data) to XLSX/CSV.',
)]
class ExportCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SpreadsheetExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workflow', InputArgument::REQUIRED, 'The tl_workflow ID.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'xlsx or csv', 'xlsx')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output file path.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->framework->initialize();

        $workflow = WorkflowModel::findByPk((int) $input->getArgument('workflow'));

        if (null === $workflow) {
            $io->error('Workflow not found.');

            return Command::FAILURE;
        }

        $result = $this->exporter->export($workflow, (string) $input->getOption('format'));
        $path = (string) ($input->getOption('out') ?: sys_get_temp_dir().'/'.$result['filename']);

        file_put_contents($path, $result['content']);
        $io->success(sprintf('Exported %d bytes to %s', \strlen($result['content']), $path));

        return Command::SUCCESS;
    }
}
