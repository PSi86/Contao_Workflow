<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Contao\Date;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Service\ImportLog;
use Psimandl\WorkflowBundle\Service\ImportSummary;

/**
 * Renders the import log of a workflow as a read-only block in the edit mask.
 *
 * This is the answer to "how did it get like this?". An import's outcome depends on the runs
 * before it – row numbers follow the file, answered entries stay frozen, the mode decides
 * about entries a run did not see – so a mistake can surface two runs after it was made.
 * Reading it back off the data is guesswork; here it is written down.
 *
 * A pseudo field (input_field_callback, no column of its own), resolved by class name via
 * System::importStatic() – the service must therefore be public.
 */
class ImportLogListener
{
    /** Runs shown; older ones stay in the table (see PurgeWorkflowImportLogCron). */
    private const LIMIT = 10;

    public function __construct(
        private readonly ImportLog $log,
        private readonly ImportSummary $summary,
    ) {
    }

    public function render(DataContainer $dc): string
    {
        $id = (int) ($dc->id ?? 0);
        $rows = $this->log->recent($id, self::LIMIT);
        $lang = $GLOBALS['TL_LANG']['tl_workflow'] ?? [];

        $GLOBALS['TL_CSS']['workflow_backend'] = 'bundles/contaoworkflow/workflow-backend.css';

        if ([] === $rows) {
            return '<div class="widget wf-import-log"><h3>'.($lang['importLog'][0] ?? 'Importprotokoll').'</h3>'
                .'<p class="tl_info">'.($lang['importLogEmpty'] ?? 'Für diesen Workflow wurde noch kein Import ausgeführt.').'</p></div>';
        }

        $html = '<div class="widget wf-import-log"><h3>'.($lang['importLog'][0] ?? 'Importprotokoll').'</h3>';
        $html .= '<p class="tl_help" style="margin:0 0 .6em">'.($lang['importLog'][1] ?? '').'</p>';
        $html .= '<table class="tl_listing showColumns"><thead><tr>'
            .'<th>'.($lang['importLogTime'] ?? 'Zeitpunkt').'</th>'
            .'<th>'.($lang['importLogMode'] ?? 'Modus').'</th>'
            .'<th>'.($lang['importLogUser'] ?? 'Ausgelöst von').'</th>'
            .'<th>'.($lang['importLogSource'] ?? 'Quelldatei').'</th>'
            .'<th>'.($lang['importLogResult'] ?? 'Ergebnis').'</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                .'<td>'.Date::parse('d.m.Y H:i', (int) $row['tstamp']).'</td>'
                .'<td>'.$this->modeLabel((string) $row['mode']).'</td>'
                .'<td>'.StringUtil::specialchars((string) $row['triggeredBy']).'</td>'
                .'<td>'.$this->source($row).'</td>'
                .'<td>'.$this->outcome($row).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table>';

        $total = $this->log->count($id);

        if ($total > \count($rows)) {
            $html .= '<p class="tl_help" style="margin:.4em 0 0">'
                .sprintf($lang['importLogMore'] ?? '… und %d ältere Läufe.', $total - \count($rows))
                .'</p>';
        }

        return $html.'</div>';
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
     */
    private function outcome(array $row): string
    {
        if ('failed' === (string) $row['state']) {
            return '<strong style="color:#b33;">'
                .($GLOBALS['TL_LANG']['tl_workflow']['importLogFailed'] ?? 'Fehlgeschlagen')
                .'</strong><br>'.StringUtil::specialchars((string) ($row['error'] ?? ''));
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
