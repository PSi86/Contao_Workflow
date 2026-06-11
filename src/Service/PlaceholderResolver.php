<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * Single source of the workflow placeholders so that the exact same token works
 * in the PDF, the Notification Center mails and matches the export/DB columns.
 *
 * Canonical tokens (identical everywhere, Notification-Center safe):
 *   ##data_<slug>##  every data column (source columns incl. stored answer values)
 *   ##var_<slug>##   every master/letterhead variable (Jahr, Verein, …)
 *   ##email##        recipient address
 *   ##workflow_title##
 *
 * The <slug> is the lower-cased column/variable name with every non
 * [A-Za-z0-9_] character replaced by "_" (e.g. "davon Spende" -> "davon_spende").
 * In PDFs the raw column/variable name (##Davon Spende##) is additionally
 * accepted as a backwards-compatible alias; mails only use the canonical form
 * because the Notification Center token names may not contain spaces.
 */
class PlaceholderResolver
{
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

        foreach ($data as $key => $value) {
            $tokens['data_'.$this->normalize((string) $key)] = (string) $value;
        }
        foreach ($vars as $key => $value) {
            $tokens['var_'.$this->normalize((string) $key)] = (string) $value;
        }

        $tokens['email'] = $email;
        $tokens['workflow_title'] = $workflowTitle;

        return $tokens;
    }

    /**
     * "##token##" => value map for replacing placeholders in a PDF text. Contains
     * the canonical tokens plus the raw-name aliases. Values are passed through
     * $esc so they are safe to embed in the (HTML) document.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $vars
     * @param callable(string):string $esc
     * @param array<string, string> $extraTokens additional canonical tokens
     *                                           (raw values, e.g. ##stmt_*##)
     *
     * @return array<string, string>
     */
    public function pdfTokenMap(array $data, array $vars, string $email, string $workflowTitle, callable $esc, array $extraTokens = []): array
    {
        $map = [];

        foreach ($this->canonicalTokens($data, $vars, $email, $workflowTitle) as $name => $value) {
            $map['##'.$name.'##'] = $esc($value);
        }

        // Backwards-compatible raw-name aliases (PDF only).
        foreach ($data as $key => $value) {
            $map['##'.$key.'##'] = $esc((string) $value);
        }
        foreach ($vars as $key => $value) {
            $map['##'.$key.'##'] = $esc((string) $value);
        }

        foreach ($extraTokens as $name => $value) {
            $map['##'.$name.'##'] = $esc((string) $value);
        }

        return $map;
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
     * contexts such as the PDF file name. Supports canonical + raw-name aliases.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $vars
     */
    public function fill(string $text, array $data, array $vars, string $email, string $workflowTitle): string
    {
        $map = [];

        foreach ($this->canonicalTokens($data, $vars, $email, $workflowTitle) as $name => $value) {
            $map['##'.$name.'##'] = $value;
        }
        foreach ($data as $key => $value) {
            $map['##'.$key.'##'] = (string) $value;
        }
        foreach ($vars as $key => $value) {
            $map['##'.$key.'##'] = (string) $value;
        }

        return strtr($text, $map);
    }

    public function normalize(string $name): string
    {
        // Transliterate the common German characters first so the slugs stay
        // readable (e.g. "Tätigkeit" -> "taetigkeit", "Straße" -> "strasse").
        $name = strtr($name, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'ß' => 'ss',
        ]);

        $name = preg_replace('/[^A-Za-z0-9_]+/', '_', $name) ?? '';

        return strtolower(trim($name, '_'));
    }
}
