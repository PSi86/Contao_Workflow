<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener;

use Psimandl\WorkflowBundle\Service\CronHealth;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records every call of a real cron – the /_contao/cron URL and the "contao:cron" command – in
 * {@see CronHealth}, the moment it arrives and before any cron job runs. Page views that run
 * the cron at kernel.terminate never pass through here, which is the point.
 */
class CronCallListener
{
    public function __construct(private readonly CronHealth $cronHealth)
    {
    }

    /**
     * Priority below the router listener (32), which sets the _route attribute.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Contao only runs the cron for a GET on this route (FrontendController::cronAction).
        if ('contao_frontend_cron' !== $request->attributes->get('_route') || !$request->isMethod('GET')) {
            return;
        }

        $this->cronHealth->recordCall(CronHealth::TRIGGER_URL);
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ('contao:cron' === $event->getCommand()?->getName()) {
            $this->cronHealth->recordCall(CronHealth::TRIGGER_CLI);
        }
    }
}
