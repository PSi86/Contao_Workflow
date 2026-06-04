<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * Produces unguessable tokens used in the individual participant links.
 */
class TokenGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
