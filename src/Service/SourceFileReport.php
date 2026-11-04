<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\Environment;
use Contao\Message;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Symfony\Component\Routing\RouterInterface;

/**
 * The back-end messages after the bundle stored or moved a source file (3.6.2) – the same
 * sentences whether it happened in the edit mask or in the overview.
 *
 * They say where the file went, that nothing was overwritten (E12), that the previous file is
 * still there (E5) – and, loudest, when that previous file is still public: once the workflow
 * no longer reads it, no other warning would ever mention it again.
 */
class SourceFileReport
{
    public function __construct(
        private readonly SourceFilePrivacy $privacy,
        private readonly SourceFilePermission $permission,
        private readonly RouterInterface $router,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $projectDir,
    ) {
    }

    /**
     * The warning for a workflow whose source file can be downloaded without a login, with the
     * button that moves it to the protected folder – or, for a user who may not change the
     * source file, who can. Null when the file is protected (or missing: SourceFileInfoListener
     * says that).
     *
     * One text for the edit mask and the overview; $return says where the button leads back to.
     *
     * @param 'edit'|'dashboard' $return
     */
    public function warning(WorkflowModel $workflow, string $return = 'dashboard'): ?string
    {
        $path = $this->privacy->sourcePath($workflow);

        if (null === $path || !is_file($this->projectDir.'/'.$path)) {
            return null;
        }

        $folder = $this->privacy->publicFolderOf($path);

        if (null === $folder) {
            return null;
        }

        $lang = $this->lang();
        $text = sprintf($lang['public_warning'] ?? '%s %s %s', $this->esc($path), $this->link($path), $this->esc($folder));

        if (!$this->permission->canChange()) {
            return $text.' '.($lang['move_no_permission'] ?? '');
        }

        $url = $this->router->generate('workflow_source_protect', ['id' => (int) $workflow->id])
            .'?rt='.$this->csrfTokenManager->getDefaultTokenValue()
            .('edit' === $return ? '&return=edit' : '');
        $confirm = sprintf($lang['move_confirm'] ?? '%s', $this->privacy->protectedFolder());

        return $text.' <a href="'.$this->esc($url).'" class="tl_submit" style="margin-left:.4em;" data-turbo="false"'
            .' onclick="return confirm('.$this->esc((string) json_encode($confirm, \JSON_UNESCAPED_UNICODE)).')">'
            .$this->esc($lang['move_button'] ?? 'move').'</a>';
    }

    /**
     * @param array{path: string, requested: string, renamed: bool} $stored   from SourceFileStore::store()
     * @param string|null                                            $previous project-relative path of the file it replaces
     */
    public function uploaded(array $stored, ?string $previous, int $workflowId): void
    {
        $lang = $this->lang();

        Message::addConfirmation(sprintf($lang['uploaded'] ?? '%s %s', $this->esc(basename($stored['path'])), $this->esc(\dirname($stored['path']))));

        if ($stored['renamed']) {
            Message::addInfo(sprintf($lang['uploaded_renamed'] ?? '%s %s', $this->esc($stored['requested']), $this->esc(basename($stored['path']))));
        }

        if (null === $previous || $previous === $stored['path']) {
            return;
        }

        $public = $this->privacy->publicFolderOf($previous);

        if (null !== $public && is_file($this->projectDir.'/'.$previous)) {
            Message::addError(sprintf($lang['previous_public'] ?? '%s %s', $this->esc($previous), $this->link($previous)));
        } else {
            Message::addInfo(sprintf($lang['previous_kept'] ?? '%s', $this->esc($previous)));
        }

        $others = $this->titles(array_filter(
            $this->privacy->workflowsUsing($previous),
            static fn (array $workflow): bool => $workflow['id'] !== $workflowId && $workflow['path'] === $previous,
        ));

        if ('' !== $others) {
            Message::addInfo(sprintf($lang['previous_shared'] ?? '%s', $others));
        }
    }

    /**
     * @param array{path: string, requested: string, renamed: bool} $moved from SourceFileStore::moveToProtected()
     * @param string                                                 $from  project-relative path it was moved from
     */
    public function moved(array $moved, string $from, int $workflowId): void
    {
        $lang = $this->lang();

        Message::addConfirmation(sprintf($lang['moved'] ?? '%s %s', $this->esc($moved['path']), $this->esc($this->url($from))));

        if ($moved['renamed']) {
            Message::addInfo(sprintf($lang['moved_renamed'] ?? '%s %s', $this->esc($moved['requested']), $this->esc(basename($moved['path']))));
        }

        $others = $this->titles(array_filter(
            $this->privacy->workflowsUsing($moved['path']),
            static fn (array $workflow): bool => $workflow['id'] !== $workflowId,
        ));

        if ('' !== $others) {
            Message::addInfo(sprintf($lang['moved_shared'] ?? '%s', $others));
        }
    }

    /**
     * The absolute public URL of a file in a public folder.
     */
    public function url(string $path): string
    {
        return rtrim((string) Environment::get('url').(string) Environment::get('path'), '/').$this->privacy->publicUrlPath($path);
    }

    /**
     * The public URL as a link that opens in a new tab – the quickest way to see the problem.
     */
    public function link(string $path): string
    {
        $url = $this->esc($this->url($path));

        return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>';
    }

    /**
     * @param array<int, array{title: string}> $workflows
     */
    public function titles(array $workflows): string
    {
        return implode(', ', array_map(fn (array $workflow): string => $this->esc($workflow['title']), $workflows));
    }

    /**
     * @return array<string, string>
     */
    private function lang(): array
    {
        return $GLOBALS['TL_LANG']['workflow_privacy'] ?? [];
    }

    private function esc(string $value): string
    {
        return StringUtil::specialchars($value);
    }
}
