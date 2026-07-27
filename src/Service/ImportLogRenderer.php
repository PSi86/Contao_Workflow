<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\Date;
use Contao\StringUtil;
use Contao\System;

/**
 * Renders the import log of a workflow as a table.
 *
 * One renderer for both places it is shown – the workflow's own section in the edit mask and
 * the dialog in the overview. They must not drift apart: this is the record someone consults
 * to explain a state, and two versions of it would be two answers to the same question.
 *
 * Returns the block itself, without a widget wrapper: the edit mask needs its own (see
 * ImportLogListener), the dialog does not.
 */
class ImportLogRenderer
{
    /** Runs shown; older ones stay in the table (see Cron\PurgeWorkflowImportLogCron). */
    public const LIMIT = 10;

    public function __construct(
        private readonly ImportLog $log,
        private readonly ImportSummary $summary,
    ) {
    }

    public function render(int $workflowId): string
    {
        $lang = $this->labels();
        $rows = $this->log->recent($workflowId, self::LIMIT);

        if ([] === $rows) {
            return '<p class="tl_info">'.$this->label($lang, 'importLogEmpty', 'Für diesen Workflow wurde noch kein Import ausgeführt.').'</p>';
        }

        $html = '<table class="tl_listing showColumns wf-import-log-table"><thead><tr>'
            .'<th>'.$this->label($lang, 'importLogTime', 'Zeitpunkt').'</th>'
            .'<th>'.$this->label($lang, 'importLogMode', 'Modus').'</th>'
            .'<th>'.$this->label($lang, 'importLogUser', 'Ausgelöst von').'</th>'
            .'<th>'.$this->label($lang, 'importLogSource', 'Quelldatei').'</th>'
            .'<th>'.$this->label($lang, 'importLogResult', 'Ergebnis').'</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                .'<td>'.Date::parse('d.m.Y H:i', (int) $row['tstamp']).'</td>'
                .'<td>'.$this->modeLabel((string) $row['mode']).'</td>'
                .'<td>'.StringUtil::specialchars((string) $row['triggeredBy']).'</td>'
                .'<td>'.$this->source($row).'</td>'
                .'<td>'.$this->outcome($row, $lang).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table>';

        $total = $this->log->count($workflowId);

        if ($total > \count($rows)) {
            $html .= '<p class="tl_help" style="margin:.4em 0 0">'
                .sprintf($this->label($lang, 'importLogMore', '… und %d ältere Läufe.'), $total - \count($rows))
                .'</p>';
        }

        return $html;
    }

    /**
     * The heading/description of the log, for whoever frames it.
     *
     * @return array{0: string, 1: string}
     */
    public function heading(): array
    {
        $lang = $this->labels();
        $entry = $lang['importLog'] ?? [];

        return [
            \is_array($entry) ? (string) ($entry[0] ?? 'Importprotokoll') : 'Importprotokoll',
            \is_array($entry) ? (string) ($entry[1] ?? '') : '',
        ];
    }

    /**
     * The labels live in tl_workflow; the overview does not load that file on its own.
     *
     * @return array<string, mixed>
     */
    private function labels(): array
    {
        if (!isset($GLOBALS['TL_LANG']['tl_workflow']['importLog'])) {
            System::loadLanguageFile('tl_workflow');
        }

        return $GLOBALS['TL_LANG']['tl_workflow'] ?? [];
    }

    /**
     * @param array<string, mixed> $lang
     */
    private function label(array $lang, string $key, string $fallback): string
    {
        $value = $lang[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : $fallback;
    }

    private function modeLabel(string $mode): string
    {
        return 'absolute' === $mode
            ? ($GLOBALS['TL_LANG']['workflow_dashboard']['import_absolute'] ?? 'Absolut')
            : ($GLOBALS['TL_LANG']['workflow_dashboard']['import_add'] ?? 'Additiv');
    }

    /**
     * File name plus the first characters of its checksum: the name alone does not tell two
     * runs apart when the file was overwritten in place, which is the case that produces the
     * most confusing results.
     *
     * @param array<string, mixed> $row
     */
    private function source(array $row): string
    {
        $file = (string) $row['sourceFile'];
        $name = '' !== $file ? basename($file) : '–';
        $sheet = (string) $row['sourceSheet'];
        $hash = (string) $row['sourceHash'];

        return StringUtil::specialchars($name)
            .('' !== $sheet ? ' <span style="color:#999">('.StringUtil::specialchars($sheet).')</span>' : '')
            .('' !== $hash ? '<br><span style="color:#999;font-size:.85em">'.substr($hash, 0, 8).'</span>' : '');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $lang
     */
    private function outcome(array $row, array $lang): string
    {
        if ('failed' === (string) $row['state']) {
            return '<strong style="color:#b33;">'.$this->label($lang, 'importLogFailed', 'Fehlgeschlagen').'</strong>'
                .'<br>'.StringUtil::specialchars((string) ($row['error'] ?? ''));
        }

        /** @var array<string, mixed> $summary */
        $summary = \is_array($row['summary']) ? $row['summary'] : [];
        $parts = [];

        foreach ($this->summary->counts($summary) as $label => $value) {
            $parts[] = StringUtil::specialchars($label).': <strong>'.$value.'</strong>';
        }

        $html = implode(' &middot; ', $parts);

        // The same sentences the run showed at the time – that is what makes the log usable
        // as an explanation and not just as a tally.
        foreach ($this->summary->notes($summary, (string) $row['mode']) as $note) {
            $html .= '<br><span style="color:#666">'.StringUtil::specialchars($note).'</span>';
        }

        foreach (\is_array($row['problems']) ? $row['problems'] : [] as $problem) {
            $html .= '<br><span style="color:#b33">'.StringUtil::specialchars((string) $problem).'</span>';
        }

        return $html;
    }
}
