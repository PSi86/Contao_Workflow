<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\Config;
use Contao\Date;
use Contao\DataContainer;
use Contao\StringUtil;
use Contao\System;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SourceFileStatus;

/**
 * Prints, directly under the source-file picker, which file the workflow is reading and how it
 * relates to the last import: modification date, checksum, and whether that is still the state
 * the current data was imported from.
 *
 * The reason is a reported failure mode that looked like caching: a corrected export is
 * uploaded under a slightly different name, lands NEXT to the configured file instead of
 * replacing it, and the workflow keeps importing the untouched original – successfully, and
 * therefore silently. The line "Stand des letzten Imports" on a file one believes to have just
 * replaced is what makes that visible.
 *
 * A pseudo field (input_field_callback, no column of its own), resolved by class name via
 * System::importStatic() – the service must therefore be public.
 */
class SourceFileInfoListener
{
    public function __construct(private readonly SourceFileStatus $status)
    {
    }

    public function render(DataContainer $dc): string
    {
        $workflow = $dc->id ? WorkflowModel::findByPk((int) $dc->id) : null;

        if (null === $workflow) {
            return '';
        }

        $info = $this->status->describe($workflow);

        // Nothing chosen yet: the picker above says everything there is to say.
        if (SourceFileStatus::STATE_NONE === $info['state']) {
            return '';
        }

        System::loadLanguageFile('workflow_messages');
        $lang = $GLOBALS['TL_LANG']['workflow_source'] ?? [];

        // Like the import log: an input_field_callback is inserted raw, so the block brings its
        // own "clr" – otherwise it slides up next to the preceding half-width field.
        $html = '<div class="widget clr wf-source-info">';

        if (SourceFileStatus::STATE_MISSING === $info['state']) {
            return $html.$this->box('tl_error', $this->text($lang, 'missing')).'</div>';
        }

        $html .= '<p class="tl_help" style="margin:0 0 .4em">'.$this->facts($info).'</p>';
        $html .= $this->verdict($info, $lang);

        return $html.'</div>';
    }

    /**
     * The bare facts about the file, in one line.
     *
     * @param array<string, mixed> $info
     */
    private function facts(array $info): string
    {
        $parts = [
            '<strong>'.StringUtil::specialchars((string) $info['path']).'</strong>',
            System::getReadableSize((int) $info['size']),
        ];

        if ((int) $info['modified'] > 0) {
            $parts[] = 'geändert '.Date::parse((string) Config::get('datimFormat'), (int) $info['modified']);
        }

        if ('' !== (string) $info['hash']) {
            // Eight characters are plenty to tell two versions of the same export apart, and
            // short enough to compare by eye against the import log.
            $parts[] = 'Prüfsumme '.substr((string) $info['hash'], 0, 8);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $lang
     */
    private function verdict(array $info, array $lang): string
    {
        $when = (int) $info['lastImportAt'] > 0
            ? Date::parse((string) Config::get('datimFormat'), (int) $info['lastImportAt'])
            : '';

        // The more specific finding, and then nothing else: the current data was produced from
        // a different file than the one selected now – exactly the mistake this block exists
        // for. Adding "the file changed since the last import" underneath would compare against
        // that other file's checksum and read as a contradiction.
        if (true === $info['otherFile']) {
            return $this->box('tl_error', sprintf(
                $this->text($lang, 'other_file'),
                StringUtil::specialchars((string) $info['lastImportPath']),
                $when,
            ));
        }

        return match ($info['state']) {
            SourceFileStatus::STATE_NEVER_IMPORTED => $this->box('tl_info', $this->text($lang, 'never')),
            SourceFileStatus::STATE_CHANGED => $this->box('tl_info', '' !== $when
                ? sprintf($this->text($lang, 'changed'), $when)
                : $this->text($lang, 'changed_unknown')),
            SourceFileStatus::STATE_CURRENT => $this->box('tl_confirm', '' !== $when
                ? sprintf($this->text($lang, 'current'), $when)
                : $this->text($lang, 'current_unknown')),
            default => '',
        };
    }

    private function box(string $class, string $text): string
    {
        return '<p class="'.$class.'" style="margin:0 0 .4em">'.$text.'</p>';
    }

    /**
     * @param array<string, mixed> $lang
     */
    private function text(array $lang, string $key): string
    {
        $value = $lang[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : $key;
    }
}
