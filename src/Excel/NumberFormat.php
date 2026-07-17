<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

/**
 * What an Excel number-format code means, reduced to the parts this bundle acts on.
 *
 * This is the single description of "how a source column is formatted" and travels
 * through the whole chain: {@see FormatCodeParser} produces it from a cell's format
 * code, {@see ValueFormatter} renders a value with it, {@see ColumnCompatibility}
 * decides whether a column may back a "number" question, and the surviving format is
 * snapshotted onto tl_workflow_question so the form, the live preview, the PDF and the
 * export agree without re-reading the source file.
 *
 * Value object – never resolved from the container.
 */
final class NumberFormat
{
    /** No explicit format ("General"): a plain number, decimals as the value carries them. */
    public const KIND_GENERAL = 'general';

    /** An explicit number/currency format with a fixed number of decimals. */
    public const KIND_NUMBER = 'number';

    public const KIND_PERCENT = 'percent';
    public const KIND_SCIENTIFIC = 'scientific';
    public const KIND_FRACTION = 'fraction';
    public const KIND_DATETIME = 'datetime';
    public const KIND_TEXT = 'text';

    /**
     * @param string   $kind     one of the KIND_* constants
     * @param int|null $decimals fixed decimals, or null for KIND_GENERAL ("as many as the value has")
     * @param bool     $grouping whether the mask groups thousands
     * @param string   $currency currency symbol carried by the mask ("€", "EUR", …), '' for none
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?int $decimals = null,
        public readonly bool $grouping = false,
        public readonly string $currency = '',
    ) {
    }

    public static function general(): self
    {
        return new self(self::KIND_GENERAL);
    }

    public static function number(int $decimals, bool $grouping = false, string $currency = ''): self
    {
        return new self(self::KIND_NUMBER, max(0, $decimals), $grouping, $currency);
    }

    /**
     * A format this bundle deliberately does not re-render (percent, scientific,
     * fraction, date/time, text).
     */
    public static function of(string $kind): self
    {
        return new self($kind);
    }

    /**
     * Whether a value with this format can be rendered as a German number. The other
     * kinds keep PhpSpreadsheet's own output.
     */
    public function isNumeric(): bool
    {
        return \in_array($this->kind, [self::KIND_GENERAL, self::KIND_NUMBER], true);
    }

    public function hasCurrency(): bool
    {
        return '' !== $this->currency;
    }

    /**
     * The same format without its currency symbol – a "number" question ignores the
     * symbol when parsing and validating (it stays on the stored/printed value).
     */
    public function withoutCurrency(): self
    {
        return new self($this->kind, $this->decimals, $this->grouping);
    }

    /**
     * Two formats are interchangeable for a column when they differ only in their
     * currency symbol: the symbol never changes the numeric meaning of the digits.
     */
    public function matchesIgnoringCurrency(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->decimals === $other->decimals
            && $this->grouping === $other->grouping;
    }

    /**
     * Compact JSON snapshot for tl_workflow_question.numberFormat.
     *
     * @return array{kind: string, decimals: int|null, grouping: bool, currency: string}
     */
    public function toArray(): array
    {
        return [
            'kind'     => $this->kind,
            'decimals' => $this->decimals,
            'grouping' => $this->grouping,
            'currency' => $this->currency,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $decimals = $data['decimals'] ?? null;

        return new self(
            \is_string($data['kind'] ?? null) ? $data['kind'] : self::KIND_GENERAL,
            null === $decimals ? null : (int) $decimals,
            (bool) ($data['grouping'] ?? false),
            (string) ($data['currency'] ?? ''),
        );
    }
}
