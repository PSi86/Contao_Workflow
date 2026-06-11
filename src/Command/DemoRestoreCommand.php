<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Command;

use Psimandl\WorkflowBundle\Service\DemoWorkflowSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'workflow:demo:restore',
    description: 'Removes and recreates the synthetic demo workflow (same as the back-end restore button).',
)]
class DemoRestoreCommand extends Command
{
    public function __construct(private readonly DemoWorkflowSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workflow = $this->seeder->seed();

        $io->success(sprintf('Demo workflow restored (id %d): %s', (int) $workflow->id, (string) $workflow->title));

        return Command::SUCCESS;
    }
}
