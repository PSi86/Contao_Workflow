<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

/**
 * The top-level verdict of one collector run, independent of how many messages it moved:
 * is the bounce mailbox usable right now, and if not, why. {@see BounceCollector::collect()}
 * returns it; {@see BounceCollector::reconcileHealth()} persists it and the back-end overview
 * turns it into a banner.
 *
 * Value object – never resolved from the container.
 */
final class BounceOutcome
{
    /**
     * @param string $state   one of the {@see BounceHealth}::STATE_* constants
     * @param string $message human-readable detail for a config error (empty otherwise)
     */
    public function __construct(
        public readonly string $state,
        public readonly string $message = '',
    ) {
    }

    public static function ok(): self
    {
        return new self(BounceHealth::STATE_OK);
    }

    public static function unconfigured(): self
    {
        return new self(BounceHealth::STATE_UNCONFIGURED);
    }

    public static function configError(string $message): self
    {
        return new self(BounceHealth::STATE_CONFIG_ERROR, $message);
    }
}
