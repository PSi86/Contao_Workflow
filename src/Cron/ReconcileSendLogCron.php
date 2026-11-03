<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Psimandl\WorkflowBundle\Service\Delivery\SendLogReconciler;

/**
 * Resolves send-log rows that wait for a result that can no longer come (see
 * SendLogReconciler). Two cheap queries when there is nothing to do.
 */
#[AsCronJob('*/15 * * * *')]
class ReconcileSendLogCron
{
    public function __construct(private readonly SendLogReconciler $reconciler)
    {
    }

    public function __invoke(): void
    {
        $this->reconciler->resolveOrphans();
    }
}
