<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;
use Psimandl\TrainerWorkflowBundle\Service\WorkflowMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'trainer:send',
    description: 'Sends invitations (status 0 → 1) or, with --reminder, reminders (status 1) for a workflow.',
)]
class SendCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly WorkflowMailer $workflowMailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workflow', InputArgument::REQUIRED, 'The tl_trainer_workflow ID.')
            ->addOption('reminder', null, InputOption::VALUE_NONE, 'Send reminders instead of invitations.')
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

        $isReminder = (bool) $input->getOption('reminder');

        try {
            $sent = $isReminder
                ? $this->workflowMailer->sendReminders($workflow)
                : $this->workflowMailer->sendInvitations($workflow);
        } catch (\Throwable $e) {
            $io->error('Sending failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s sent: %d', $isReminder ? 'Reminders' : 'Invitations', $sent));

        return Command::SUCCESS;
    }
}
