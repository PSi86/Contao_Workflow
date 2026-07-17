<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\InsertTag\InsertTagParser;

/**
 * Single source of the workflow placeholders so that the exact same token works
 * in the PDF, the Notification Center mails and matches the export/DB columns.
 *
 * Canonical tokens (identical everywhere, Notification-Center safe):
 *   ##data_<slug>##  every data column (source columns incl. stored answer values)
 *   ##letterhead_<slug>##   every master/letterhead variable (Verein, Ort, …)
 *   ##system_year## / ##system_month## / ##system_today## / ##system_time## /
 *   ##system_datetime##     built-in date/time, computed at render time
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
    /**
     * The capital forms map to "Ae"/"Oe"/"Ue", not "ae"/"oe"/"ue": {@see normalize()} lower-cases
     * afterwards and cannot tell the difference, but the case-preserving callers
     * ({@see fileSlug()}, PdfGenerator::sanitizeFileName()) can – "Übungsleiter" has to become
     * "Uebungsleiter", not "uebungsleiter".
     */
    /**
     * Inline formatting markers (BBCode style) supported in the document texts and
     * Textbausteine, mapped to the whitelisted HTML tags they produce. The same
     * marker/tag idea as ##tokens##/{{insert-tags}}: a delimited marker that is
     * transformed – but square brackets survive htmlspecialchars unchanged, so they
     * can be converted AFTER escaping (only these exact markers become real tags,
     * everything else the user typed stays escaped).
     */
    private const FORMATTING_TAGS = [
        'b' => 'strong',
        'i' => 'em',
        'u' => 'u',
    ];

    public function __construct(
        private readonly InsertTagParser $insertTagParser,
        private readonly Slugger $slugger,
    ) {
    }

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

        // Built-in ##system_*## tokens win unconditionally: they live in their own
        // prefix and can never collide with data_/letterhead_ slugs.
        foreach ($this->systemTokens() as $name => $value) {
            $tokens[$name] = $value;
        }

        $tokens['email'] = $email;
        $tokens['workflow_title'] = $workflowTitle;

        return $tokens;
    }

    /**
     * Built-in, context-independent tokens computed at render time, available in
     * every context (PDF body, heading, file name, mail, statements) without any
     * configuration. The suffix is a fixed, closed vocabulary – only these resolve,
     * anything else stays literal. Shared with the integrity check so it can warn
     * on an unknown ##system_*## token.
     *
     * @return array<string, string> token name (no ## marks) => current value
     */
    public function systemTokens(): array
    {
        return [
            'system_year'     => date('Y'),        // 2026
            'system_month'    => date('m'),        // 07
            'system_today'    => date('d.m.Y'),    // 01.07.2026
            'system_time'     => date('H:i'),      // 14:30
            'system_datetime' => date('d.m.Y H:i'), // 01.07.2026 14:30
        ];
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
        // Escape the admin template first, then resolve Contao insert tags ({{...}} –
        // their syntax survives htmlspecialchars) so an insert tag's output is not
        // re-escaped, then apply the inline formatting markers ([b]/[i]/[u], which also
        // survive escaping) and finally substitute the ##tokens## with their (escaped,
        // and for Textbausteine also formatted) values. User data only enters through
        // the token map and is therefore never parsed as an insert tag; only the admin
        // template and the Textbaustein tokens carry formatting, never source columns.
        return strtr(
            $this->applyFormatting($this->parseInsertTags($esc($text))),
            $this->pdfTokenMap($data, $vars, $email, $workflowTitle, $esc, $extraTokens),
        );
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
        return strtr(
            $this->parseInsertTags($text),
            $this->tokenMap($data, $vars, $email, $workflowTitle, static fn (string $value): string => $value),
        );
    }

    /**
     * Resolves Contao insert tags ({{...}}) in an admin-authored template (heading,
     * intro, rule body, statement, file name …). Only the template is parsed – the
     * ##tokens## carrying user-submitted data are substituted afterwards, so form data
     * can never inject an insert tag. replaceInline() escapes the value of a text
     * insert tag and passes an HTML insert tag through, so the result is safe to embed.
     */
    private function parseInsertTags(string $text): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        return $this->insertTagParser->replaceInline($text);
    }

    /**
     * Converts the inline formatting markers ([b]/[i]/[u], case-insensitive) of an
     * ALREADY html-escaped string into the whitelisted tags <strong>/<em>/<u>.
     * Because the text is escaped first, only these exact markers become real tags –
     * any other "<...>" the user typed stays escaped (same safety model as the
     * insert-tag pass). Use this on admin-authored HTML output only.
     */
    public function applyFormatting(string $escaped): string
    {
        if (!str_contains($escaped, '[')) {
            return $escaped;
        }

        return preg_replace_callback(
            '#\[(/?)([biu])\]#i',
            static function (array $m): string {
                $tag = self::FORMATTING_TAGS[strtolower($m[2])];

                return '<'.$m[1].$tag.'>';
            },
            $escaped,
        ) ?? $escaped;
    }

    /**
     * Removes the inline formatting markers for plain-text contexts (mails, template
     * mode), leaving the readable text without stray "[b]" markup.
     */
    public function stripFormatting(string $text): string
    {
        if (!str_contains($text, '[')) {
            return $text;
        }

        return preg_replace('#\[/?[biu]\]#i', '', $text) ?? $text;
    }

    /**
     * The ##token## slug of a column/variable name (lowercase ASCII). Delegates to the shared
     * {@see Slugger}: for German it is bit-identical to the former hand-rolled table, so every
     * existing ##data_*## / ##letterhead_*## reference keeps resolving; for any other script it
     * now produces a real slug instead of an empty one (which used to collide all columns).
     */
    public function normalize(string $name): string
    {
        return $this->slugger->token($name);
    }

    /**
     * A name turned into an ASCII filename component, capitalisation preserved.
     */
    public function fileSlug(string $name): string
    {
        return $this->slugger->ascii($name);
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

        // Source columns / letterhead variables are plain data: escaped only, never
        // formatted (a "[b]" that happens to be in imported data stays literal).
        foreach ($this->canonicalTokens($data, $vars, $email, $workflowTitle) as $name => $value) {
            $map['##'.$name.'##'] = $esc($value);
        }

        // Extra tokens are the admin-authored Textbausteine (##text_*##): escaped and
        // then run through the inline formatting so [b]/[i]/[u] become real tags. In
        // the raw context (fill()) no extra tokens are passed, so this only affects the
        // HTML render (pdfTokenMap/renderPdfText).
        foreach ($extraTokens as $name => $value) {
            $map['##'.$name.'##'] = $this->applyFormatting($esc((string) $value));
        }

        return $map;
    }
}
