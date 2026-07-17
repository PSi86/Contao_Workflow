<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Command;

use Psimandl\WorkflowBundle\Service\Bounce\BounceCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the bounce collection on demand — the same work the every-15-minutes cron does, but
 * with visible per-step output for diagnosis. --dry-run inspects the mailbox without moving
 * mails or writing anything; --dsn overrides the configured DSN, so the IMAP connection can
 * be tested independently of whether .env.local is actually being read.
 */
#[AsCommand(
    name: 'workflow:bounce:collect',
    description: 'Liest das Bounce-Postfach aus und ordnet Bounces zu (auch zur Diagnose).',
)]
class BounceCollectCommand extends Command
{
    public function __construct(
        private readonly BounceCollector $collector,
        private readonly ?string $bounceImapDsn = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'IMAP-DSN überschreiben (sonst WORKFLOW_BOUNCE_IMAP_DSN).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen – nichts verschieben oder speichern.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dsn = (string) ($input->getOption('dsn') ?? '');
        $source = '--dsn';

        if ('' === $dsn) {
            $dsn = (string) $this->bounceImapDsn;
            $source = 'WORKFLOW_BOUNCE_IMAP_DSN';
        }

        $dsn = trim($dsn);

        if ('' === $dsn) {
            $io->warning(
                'Keine DSN gefunden. Weder --dsn noch WORKFLOW_BOUNCE_IMAP_DSN ist gesetzt. '
                .'Auf einer Contao-Managed-Edition mit .env.local.php wird .env.local NICHT gelesen – '
                .'siehe DEPLOYMENT.md, Abschnitt 3c.',
            );

            return Command::SUCCESS;
        }

        $io->writeln(\sprintf('DSN aus %s: %s', $source, $this->maskPassword($dsn)));
        $io->newLine();

        $outcome = $this->collector->collect(
            $dsn,
            (bool) $input->getOption('dry-run'),
            static function (string $level, string $message) use ($io): void {
                match ($level) {
                    'error' => $io->writeln('<error>'.$message.'</error>'),
                    'comment' => $io->writeln('<comment>'.$message.'</comment>'),
                    default => $io->writeln($message),
                };
            },
        );

        // Only reconcile the global health when this run used the CONFIGURED mailbox: a --dsn
        // override tests a different one, and must not move the banner that reflects the real
        // feature state. Reconciling here lets an admin fix the config, run the command, and
        // see the overview banner clear at once instead of waiting for the next cron.
        if ('WORKFLOW_BOUNCE_IMAP_DSN' === $source) {
            $this->collector->reconcileHealth($outcome);
        }

        return Command::SUCCESS;
    }

    private function maskPassword(string $dsn): string
    {
        return (string) preg_replace('#(://[^:/@]+:)[^@]*@#', '$1***@', $dsn);
    }
}
