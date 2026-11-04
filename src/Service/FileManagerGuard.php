<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the file manager may not do to source files (3.6.2, decisions E8, E9, E13 of
 * docs/dev/PLAN-source-file-privacy.md), and how to say so:
 *
 *  - move a source file, a folder holding one, or the protected folder: refused (a move is how
 *    a protected file ends up public, and the bundle's own button is the safe way to move);
 *  - delete a source file or a folder holding one: refused (nothing the workflows read
 *    disappears; everything that can be deleted is unused);
 *  - publish a folder holding a source file, or anything in or above the protected folder:
 *    refused.
 *
 * The texts come in two lengths: a tooltip on the greyed-out icon, and a message for an
 * entry Contao skipped silently in a multiple selection (DC_Folder::cutAll()/deleteAll()
 * catch the AccessDeniedException without a word).
 */
class FileManagerGuard implements ResetInterface
{
    /** @var array<string, true> messages already added in this request */
    private array $reported = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SourceFilePrivacy $privacy,
    ) {
    }

    /**
     * The tooltip saying why $path cannot be moved, or null when it can.
     */
    public function moveRefusal(string $path): ?string
    {
        [$file, $folder] = $this->split($path);

        return match (true) {
            [] !== $file => $this->text('fm_cut_source', $this->titles($file)),
            [] !== $folder => $this->text('fm_cut_folder', $this->titles($folder)),
            $this->privacy->containsProtectedFolder($path) => $this->text('fm_cut_protected'),
            default => null,
        };
    }

    /**
     * The tooltip saying why $path cannot be deleted, or null when it can.
     */
    public function deleteRefusal(string $path): ?string
    {
        [$file, $folder] = $this->split($path);

        return match (true) {
            [] !== $file => $this->text('fm_delete_source', $this->titles($file)),
            [] !== $folder => $this->text('fm_delete_folder', $this->titles($folder)),
            default => null,
        };
    }

    /**
     * The message saying why the folder $path was not published, or null when it may be.
     */
    public function publishRefusal(string $path): ?string
    {
        $workflows = $this->privacy->workflowsUsing($path);

        if ([] !== $workflows) {
            return $this->text('fm_publish_refused', StringUtil::specialchars($path), $this->titles($workflows));
        }

        if ($this->privacy->isInProtectedFolder($path) || $this->privacy->containsProtectedFolder($path)) {
            return $this->text('fm_publish_protected', StringUtil::specialchars($path));
        }

        return null;
    }

    /**
     * Says, once per request, that $path was left out of a move or delete – Contao skips the
     * refused entries of a multiple selection without telling anyone.
     */
    public function reportSkipped(string $path, bool $delete): void
    {
        $key = ($delete ? 'delete:' : 'cut:').$path;

        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;
        $workflows = $this->privacy->workflowsUsing($path);
        $name = StringUtil::specialchars($path);

        if ([] !== $workflows) {
            Message::addError($this->text($delete ? 'fm_skipped_delete' : 'fm_skipped_cut', $name, $this->titles($workflows)));
        } elseif (!$delete) {
            Message::addError($this->text('fm_skipped_cut_protected', $name));
        }
    }

    public function reset(): void
    {
        $this->reported = [];
    }

    /**
     * The workflows reading $path itself, and those reading a file below it.
     *
     * @return array{0: list<array{id: int, title: string, path: string}>, 1: list<array{id: int, title: string, path: string}>}
     */
    private function split(string $path): array
    {
        $path = trim($path, '/');
        $file = [];
        $folder = [];

        foreach ($this->privacy->workflowsUsing($path) as $workflow) {
            if ($workflow['path'] === $path) {
                $file[] = $workflow;
            } else {
                $folder[] = $workflow;
            }
        }

        return [$file, $folder];
    }

    /**
     * @param list<array{title: string}> $workflows
     */
    private function titles(array $workflows): string
    {
        $titles = array_values(array_unique(array_map(static fn (array $workflow): string => $workflow['title'], $workflows)));

        return StringUtil::specialchars(implode(', ', $titles));
    }

    private function text(string $key, string ...$args): string
    {
        $this->framework->initialize();
        $this->framework->getAdapter(System::class)->loadLanguageFile('workflow_messages');

        $pattern = $GLOBALS['TL_LANG']['workflow_privacy'][$key] ?? $key;

        return vsprintf(\is_string($pattern) ? $pattern : $key, $args);
    }
}
