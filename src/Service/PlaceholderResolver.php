<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * Single source of the workflow placeholders so that the exact same token works
 * in the PDF, the Notification Center mails and matches the export/DB columns.
 *
 * Canonical tokens (identical everywhere, Notification-Center safe):
 *   ##data_<slug>##  every data column (source columns incl. stored answer values)
 *   ##letterhead_<slug>##   every master/letterhead variable (Jahr, Verein, …)
 *   ##email##        recipient address
 *   ##workflow_title##
 *
 * The <slug> is the column/variable name with the common German characters
 * transliterated (ä->ae, ß->ss …), every remaining non [A-Za-z0-9_] character
 * replaced by "_" and the result lower-cased (e.g. "davon Spende" ->
 * "davon_spende"). This is the only accepted spelling – there are no raw-name
 * aliases, so a token is always a known prefix (data_/letterhead_/text_) or a
 * known fixed token, in every context.
 */
class PlaceholderResolver
{
    /**
     * Common German characters transliterated into ASCII so slugs (and file
     * names) stay readable (e.g. "Tätigkeit" -> "taetigkeit", "Straße" ->
     * "strasse").
     */
    private const TRANSLITERATION = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
        'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
        'ß' => 'ss',
    ];

    /**
     * Canonical, Notification-Center-safe tokens (name => raw value, no ## marks).
     *
     * @param array<string, mixed>  $data source columns incl. stored answers
     * @param array<string, mixed>  $vars master/letterhead variables
     *
     * @return array<string, string>
     */
    public function canonicalTokens(array $data, array $vars, string $email, string $workflowTitle): array
    {
        $tokens = [];

        // First-wins on a slug collision: two columns/variables that normalize to
        // the same slug would be ambiguous, so only the first claims the token and
        // any later one is not reachable via ##data_*## / ##letterhead_*## (its value is
        // still stored and exported under its raw column name). slugCollisions()
        // surfaces this to the user at import time.
        foreach ($data as $key => $value) {
            $name = 'data_'.$this->normalize((string) $key);
            $tokens[$name] ??= (string) $value;
        }
        foreach ($vars as $key => $value) {
            $name = 'letterhead_'.$this->normalize((string) $key);
            $tokens[$name] ??= (string) $value;
        }

        $tokens['email'] = $email;
        $tokens['workflow_title'] = $workflowTitle;

        return $tokens;
    }

    /**
     * Finds names that collide on the same slug. Returns slug => the colliding
     * original names in input order, only for groups with more than one name.
     * The first name of each group keeps the token (see canonicalTokens()), the
     * rest are not reachable via their placeholder.
     *
     * @param array<int|string, string> $names
     *
     * @return array<string, array<int, string>>
     */
    public function slugCollisions(array $names): array
    {
        $bySlug = [];

        foreach ($names as $name) {
            $bySlug[$this->normalize((string) $name)][] = (string) $name;
        }

        return array_filter($bySlug, static fn (array $group): bool => \count($group) > 1);
    }

    /**
     * "##token##" => value map for replacing placeholders in a PDF text. Values
     * are passed through $esc so they are safe to embed in the (HTML) document.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $vars
     * @param callable(string):string $esc
     * @param array<string, string> $extraTokens additional canonical tokens
     *                                           (raw values, e.g. ##text_*##)
     *
     * @return array<string, string>
     */
    public function pdfTokenMap(array $data, array $vars, string $email, string $workflowTitle, callable $esc, array $extraTokens = []): array
    {
        return $this->tokenMap($data, $vars, $email, $workflowTitle, $esc, $extraTokens);
    }

    /**
     * Renders a PDF text: HTML-escapes the template, then replaces ##tokens## with
     * their (already escaped) values. The ## markers survive the escaping.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $vars
     * @param callable(string):string $esc
     * @param array<string, string> $extraTokens additional canonical tokens (raw values)
     */
    public function renderPdfText(string $text, array $data, array $vars, string $email, string $workflowTitle, callable $esc, array $extraTokens = []): string
    {
        return strtr($esc($text), $this->pdfTokenMap($data, $vars, $email, $workflowTitle, $esc, $extraTokens));
    }

    /**
     * Replaces ##tokens## with their raw (unescaped) values – for non-HTML
     * contexts such as the PDF file name, the heading and the statement templates.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $vars
     */
    public function fill(string $text, array $data, array $vars, string $email, string $workflowTitle): string
    {
        return strtr($text, $this->tokenMap($data, $vars, $email, $workflowTitle, static fn (string $value): string => $value));
    }

    public function transliterate(string $value): string
    {
        return strtr($value, self::TRANSLITERATION);
    }

    public function normalize(string $name): string
    {
        $name = $this->transliterate($name);
        $name = preg_replace('/[^A-Za-z0-9_]+/', '_', $name) ?? '';

        return strtolower(trim($name, '_'));
    }

    /**
     * Shared "##token##" => value map builder. Every value (canonical and extra)
     * runs through $esc; pass an identity callback for raw output.
     *
     * @param array<string, mixed>    $data
     * @param array<string, mixed>    $vars
     * @param callable(string):string $esc
     * @param array<string, string>   $extraTokens
     *
     * @return array<string, string>
     */
    private function tokenMap(array $data, array $vars, string $email, string $workflowTitle, callable $esc, array $extraTokens = []): array
    {
        $map = [];

        foreach ($this->canonicalTokens($data, $vars, $email, $workflowTitle) as $name => $value) {
            $map['##'.$name.'##'] = $esc($value);
        }

        foreach ($extraTokens as $name => $value) {
            $map['##'.$name.'##'] = $esc((string) $value);
        }

        return $map;
    }
}
