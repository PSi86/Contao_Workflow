<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

/**
 * The verdict of {@see ColumnCompatibility}: why a column cannot back a "number"
 * question, or – when it can – the format to snapshot onto that question.
 *
 * Value object – never resolved from the container.
 */
final class ColumnCheckResult
{
    /**
     * @param array<int, string> $problems human-readable, each naming rows and values
     * @param NumberFormat|null  $format   the column's format, null when incompatible
     */
    public function __construct(
        public readonly array $problems,
        public readonly ?NumberFormat $format = null,
    ) {
    }

    public function isCompatible(): bool
    {
        return [] === $this->problems;
    }
}
