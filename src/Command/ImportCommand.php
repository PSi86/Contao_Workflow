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
// STILLGELEGT mit der Option --mode (siehe configure()):
// use Symfony\Component\Console\Input\InputOption;
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

        // STILLGELEGT: der absolute Importmodus wird nicht mehr angeboten – weder hier noch im
        // Backend (siehe den abgeschalteten Import-Dialog in be_workflow_dashboard.html5). Er
        // löschte Einträge samt bereits erzeugter PDFs, auch bereits beantwortete, sobald ihre
        // Zeile in der Quelldatei fehlte oder ausgeblendet war. Zum Reaktivieren diesen Block
        // und die Modus-Prüfung in execute() einkommentieren.
        // $this->addOption(
        //     'mode',
        //     null,
        //     InputOption::VALUE_REQUIRED,
        //     'add: only add and update (default). absolute: additionally delete entries whose row is hidden or gone from the file, including their PDFs.',
        //     SpreadsheetImporter::MODE_ADD,
        // );
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

        // STILLGELEGT mit der Option --mode (siehe configure()): jeder Lauf ist additiv.
        // $mode = (string) $input->getOption('mode');
        //
        // if (!\in_array($mode, [SpreadsheetImporter::MODE_ADD, SpreadsheetImporter::MODE_ABSOLUTE], true)) {
        //     $io->error(sprintf('Unknown mode "%s" – use "add" or "absolute".', $mode));
        //
        //     return Command::INVALID;
        // }
        $mode = SpreadsheetImporter::MODE_ADD;

        try {
            $result = $this->importer->import($workflow, $mode);
        } catch (\Throwable $e) {
            $io->error('Import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Workflow "%s" (%s): %d new, %d updated, %d left untouched (already answered), %d deleted – total %d.',
            $workflow->title,
            $mode,
            $result['inserted'],
            $result['updated'],
            $result['protected'],
            $result['removed'],
            $result['total'],
        ));

        $this->reportDetails($io, $result, $mode);

        if ([] !== $result['formulaProblems']) {
            $io->warning(
                "Formula cells without a stored result – those fields were imported empty:\n"
                .implode("\n", $result['formulaProblems']),
            );
        }

        if ([] !== $result['formatProblems']) {
            $io->warning(
                "Number format not adopted – the affected fields keep their previous format:\n"
                .implode("\n", $result['formatProblems']),
            );
        }

        $this->warnCollisions($io, $result['collisions']);

        return Command::SUCCESS;
    }

    /**
     * What the run left out or cleaned up – the console counterpart of
     * WorkflowActionController::reportImportDetails.
     *
     * @param array{hidden: int, hiddenKnown: int, duplicates: int, missing: int, removed: int, removedAnswered: int, sharedRows: int} $result
     */
    private function reportDetails(SymfonyStyle $io, array $result, string $mode): void
    {
        $notes = [];

        if ($result['hidden'] > 0) {
            $notes[] = sprintf(
                '%d hidden row(s) skipped (%d of them imported earlier).',
                $result['hidden'],
                $result['hiddenKnown'],
            );
        }

        if ($result['duplicates'] > 0) {
            $notes[] = sprintf('%d row(s) skipped: their e-mail address appears more than once.', $result['duplicates']);
        }

        if (SpreadsheetImporter::MODE_ABSOLUTE === $mode) {
            if ($result['removed'] > 0) {
                $notes[] = sprintf(
                    '%d entry/entries deleted that the file no longer shows (%d of them already answered, PDFs removed).',
                    $result['removed'],
                    $result['removedAnswered'],
                );
            }
        } elseif ($result['missing'] > 0) {
            $notes[] = sprintf(
                '%d existing entry/entries are not visible in the source file; kept (mode "add") and still mailed.',
                $result['missing'],
            );
        }

        if ($result['sharedRows'] > 0) {
            $notes[] = sprintf(
                '%d source row number(s) are claimed by more than one entry – their export order is decided by age.',
                $result['sharedRows'],
            );
        }

        if ([] !== $notes) {
            $io->note(implode("\n", $notes));
        }
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
