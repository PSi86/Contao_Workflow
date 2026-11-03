<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Cron;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\CoreBundle\Exception\CronExecutionSkippedException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\BackgroundActivity;
use Psimandl\WorkflowBundle\Service\CronHealth;
use Psimandl\WorkflowBundle\Service\SubmissionProcessor;
use Psr\Log\LoggerInterface;

/**
 * Self-healing for the confirmation step: if PDF generation or the result mail failed at
 * submission time (see SubmissionProcessor::produceConfirmation), the entry stays "responded
 * but not done" (resultDoneAt = 0) and shows up in the "Offene Vorgänge" list. This cron
 * retries it, so a transient failure (mPDF memory, a brief Notification Center hiccup) — or a
 * systemic one the admin has since fixed — recovers on its own within one interval.
 *
 * Only recent responses are auto-retried; older ones stay visible in the list for the admin
 * to resolve via the "Bestätigung neu erzeugen & senden" action, instead of being retried
 * forever.
 *
 * Generating a PDF is the most expensive thing the bundle does, so a run started by a page
 * view is handed back while a real cron is alive (see CronHealth::shouldDefer()).
 */
#[AsCronJob('*/15 * * * *')]
class ProcessPendingResultsCron
{
    private const BATCH_LIMIT = 50;

    // Keep auto-retrying a failed confirmation for a few days (covers transient causes and a
    // later admin fix), then leave it to the manual back end action.
    private const MAX_AGE = 3 * 24 * 60 * 60;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly SubmissionProcessor $submissionProcessor,
        private readonly LoggerInterface $logger,
        private readonly CronHealth $cronHealth,
        private readonly ?BackgroundActivity $activity = null,
    ) {
    }

    public function __invoke(string $scope = Cron::SCOPE_CLI): void
    {
        if ($this->cronHealth->shouldDefer($scope)) {
            throw new CronExecutionSkippedException();
        }

        $this->framework->initialize();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, pid FROM tl_workflow_entry '
            .'WHERE respondedAt > 0 AND resultDoneAt = 0 AND respondedAt > ? '
            .'ORDER BY respondedAt LIMIT '.self::BATCH_LIMIT,
            [time() - self::MAX_AGE],
        );

        if ([] === $rows) {
            return;
        }

        $done = 0;
        $failed = 0;
        $started = microtime(true);

        foreach ($rows as $row) {
            $entry = EntryModel::findByPk((int) $row['id']);
            $workflow = WorkflowModel::findByPk((int) $row['pid']);

            if (null === $entry || null === $workflow) {
                continue;
            }

            if ($this->submissionProcessor->produceConfirmation($workflow, $entry)) {
                ++$done;
            } else {
                ++$failed;
            }
        }

        // Attributes a slow request to the PDF generation (see RequestTimingListener).
        $this->activity?->record(\sprintf('Nachversand von %d Bestätigung(en)', $done + $failed), microtime(true) - $started);

        $this->logger->log(
            'info',
            \sprintf('Offene Bestätigungen nachbearbeitet: %d erfolgreich, %d weiterhin offen.', $done, $failed),
            ['contao' => new ContaoContext(self::class.'::__invoke', ContaoContext::CRON)],
        );
    }
}
