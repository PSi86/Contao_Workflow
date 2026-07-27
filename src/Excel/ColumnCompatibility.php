<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

/**
 * Decides whether a source column may back a "number" question.
 *
 * A number field round-trips a value: it shows the stored string, the participant edits
 * it, and it is written back. That only works while the column's formatting is
 * reproducible – hence the rule "no decimals, or exactly two". A column with three
 * decimals would be silently rounded on the way back; a percent column would be scaled by
 * 100. Those are not cosmetic differences, so they are refused up front instead of
 * corrupting values later. A text field has no such contract and accepts anything.
 *
 * The decimal rule guards *existing* values, so it applies to what the filled cells show.
 * It does not apply to a format that only the empty cells declare (there is nothing to
 * round-trip yet), and a field that states its own decimals lifts it entirely – the
 * question it protects against is then answered.
 *
 * The currency symbol is deliberately ignored: it carries no numeric meaning, so it never
 * makes a column incompatible (it does stay on the stored and printed value).
 *
 * Messages are German literals, matching WorkflowValidator::getSendBlockers().
 */
class ColumnCompatibility
{
    /** The only decimal counts a number field can round-trip losslessly. */
    private const ALLOWED_DECIMALS = [0, 2];

    /** Rows named per message before it falls back to "und N weitere". */
    private const EXAMPLE_ROWS = 3;

    private const KIND_LABELS = [
        NumberFormat::KIND_PERCENT    => 'Prozent',
        NumberFormat::KIND_SCIENTIFIC => 'wissenschaftlich',
        NumberFormat::KIND_FRACTION   => 'Bruch',
        NumberFormat::KIND_DATETIME   => 'Datum/Zeit',
        NumberFormat::KIND_TEXT       => 'Text',
    ];

    /**
     * @param array<int, array{row: int, format: NumberFormat, mask: string, value: float|null, text: string, empty?: bool}> $cells
     *                                                                                                                              as produced by {@see ColumnFormatAnalyzer}
     * @param int|null                                                                                                      $forcedDecimals
     *                                                                                                                              decimals configured on the field ("Nachkommastellen"), or null for
     *                                                                                                                              "derive them from the column". A configured value answers the
     *                                                                                                                              question the decimal rules below exist for, so it lifts them: it is
     *                                                                                                                              the user stating how the field rounds, not a guess from the data
     */
    public function checkNumberColumn(string $header, array $cells, ?int $forcedDecimals = null): ColumnCheckResult
    {
        $problems = [];

        /** @var array<int, array{row: int, text: string}> $textCells */
        $textCells = [];
        /** @var array<string, array<int, int>> $badKinds kind => rows */
        $badKinds = [];
        /** @var array<int, array{rows: array<int, int>, mask: string}> $byDecimals */
        $byDecimals = [];

        $grouping = false;
        $currency = '';

        // The format declared by the empty cells of the column. An empty cell is not a
        // value and must never make a column incompatible – a participant may simply have
        // nothing there yet – but its mask is a statement about the column. For an answer
        // column, which is empty in the source by definition, it is the only statement
        // there is: without reading it, "Währung mit 2 Nachkommastellen" silently became
        // "ganzzahlig, keine Währung" and rounded 25,25 to 25 on the way in.
        $declared = null;

        foreach ($cells as $cell) {
            $format = $cell['format'];

            if ($cell['empty'] ?? false) {
                if ($format->isNumeric() && null !== $format->decimals) {
                    $declared ??= $format;
                }

                continue;
            }

            if (!$format->isNumeric()) {
                $badKinds[$format->kind][] = $cell['row'];

                continue;
            }

            if (null === $cell['value']) {
                $textCells[] = ['row' => $cell['row'], 'text' => $cell['text']];

                continue;
            }

            $grouping = $grouping || $format->grouping;
            $currency = '' !== $currency ? $currency : $format->currency;

            // An explicit mask pins the decimals. "General" only pins them when the value
            // actually carries some: a plain integer fits both 0 and 2 decimals, so it must
            // not force the column either way (otherwise a hand-typed 3000 in a currency
            // column would count as a conflict).
            $decimals = $format->decimals ?? $this->decimalsOf($cell['value']);

            if (null === $format->decimals && 0 === $decimals) {
                continue;
            }

            $byDecimals[$decimals]['rows'][] = $cell['row'];
            $byDecimals[$decimals]['mask'] ??= $cell['mask'];
        }

        if ([] !== $textCells) {
            $rows = array_column($textCells, 'row');
            $problems[] = sprintf(
                'Die Spalte „%s" enthält in %s Text statt einer Zahl (z. B. „%s").',
                $header,
                $this->rowList($rows),
                $textCells[0]['text'],
            );
        }

        foreach ($badKinds as $kind => $rows) {
            $problems[] = sprintf(
                'Die Spalte „%s" ist in %s als %s formatiert. Ein Zahlenfeld verarbeitet nur Zahlen- und Währungsformate.',
                $header,
                $this->rowList($rows),
                self::KIND_LABELS[$kind] ?? $kind,
            );
        }

        foreach ($byDecimals as $decimals => $info) {
            // A configured decimal count settles this: the field then rounds the way the
            // user said, and refusing the column would only block the way there.
            if (null !== $forcedDecimals || \in_array($decimals, self::ALLOWED_DECIMALS, true)) {
                continue;
            }

            $problems[] = sprintf(
                'Die Spalte „%s" hat in %s %d Nachkommastelle(n)%s. Erlaubt sind nur 0 oder 2 Nachkommastellen.',
                $header,
                $this->rowList($info['rows']),
                $decimals,
                '' !== $info['mask'] ? sprintf(' (Format „%s")', $info['mask']) : '',
            );
        }

        // Mixed decimals leave no single format to snapshot: the field would print one
        // spelling while the neighbouring rows keep another. Unless the field states its
        // decimals – then there is a single format, the configured one.
        if (null === $forcedDecimals && \count($byDecimals) > 1) {
            $counts = array_keys($byDecimals);
            sort($counts);

            $problems[] = sprintf(
                'Die Spalte „%s" mischt %s Nachkommastellen (%s). Ein Zahlenfeld braucht ein einheitliches Format.',
                $header,
                implode(' und ', array_map('strval', $counts)),
                implode(', ', array_map(
                    fn (int $d): string => sprintf('%d → %s', $d, $this->rowList($byDecimals[$d]['rows'], 1)),
                    $counts,
                )),
            );
        }

        if ([] !== $problems) {
            $problems[] = 'Alternative: den Feldtyp „Freitext" wählen – dort wird das Format nicht geprüft.';

            return new ColumnCheckResult($problems);
        }

        // Order of authority: what the field configures, else what the values show, else
        // what the empty cells declare, else "no decimals". Grouping and the currency
        // symbol come from the empty cells only when no value contributed them – a value
        // carries its own mask and is the better witness.
        $fromValues = [] !== $byDecimals ? (int) array_key_first($byDecimals) : null;

        if (null === $fromValues && null !== $declared) {
            $grouping = $grouping || $declared->grouping;
            $currency = '' !== $currency ? $currency : $declared->currency;
        }

        $decimals = $forcedDecimals ?? $fromValues ?? $declared?->decimals ?? 0;

        return new ColumnCheckResult([], NumberFormat::number($decimals, $grouping, $currency));
    }

    /**
     * The decimals a value actually carries. PHP casts a float locale-independently since
     * 8.0, so this reads the same digits PhpSpreadsheet would print for a "General" cell.
     */
    private function decimalsOf(float $value): int
    {
        $plain = (string) $value;

        // Scientific notation carries no meaningful decimal count here.
        if (false !== stripos($plain, 'E')) {
            return 0;
        }

        $dot = strrpos($plain, '.');

        return false === $dot ? 0 : \strlen(substr($plain, $dot + 1));
    }

    /**
     * "Zeile 5", "Zeile 5, 17" or "Zeile 5, 17, 23 und 4 weiteren" – a column with 500 bad
     * rows must not produce 500 messages.
     *
     * @param array<int, int> $rows
     */
    private function rowList(array $rows, int $limit = self::EXAMPLE_ROWS): string
    {
        $shown = \array_slice($rows, 0, $limit);
        $rest = \count($rows) - \count($shown);

        $list = 'Zeile '.implode(', ', array_map('strval', $shown));

        return $rest > 0 ? sprintf('%s und %d weiteren', $list, $rest) : $list;
    }
}
