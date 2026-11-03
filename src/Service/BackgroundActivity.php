<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * Collects what the bundle's own background work spent its time on during the current
 * request (bounce check, confirmation retry), so a slow request can be attributed in the
 * system log (see RequestTimingListener): "darin: Bounce-Abruf 18,3 s".
 *
 * Request-scoped by nature: PHP-FPM runs one request per process at a time and the listener
 * drains the list at the end of each phase.
 */
class BackgroundActivity
{
    /** @var list<array{string, float}> */
    private array $records = [];

    public function record(string $label, float $seconds): void
    {
        $this->records[] = [$label, $seconds];
    }

    /**
     * Returns everything recorded since the last drain and forgets it.
     *
     * @return list<array{string, float}>
     */
    public function drain(): array
    {
        $records = $this->records;
        $this->records = [];

        return $records;
    }
}
