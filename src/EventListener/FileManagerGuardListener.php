<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\DataContainer;
use Contao\Image;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Psimandl\WorkflowBundle\Service\FileManagerGuard;
use Psimandl\WorkflowBundle\Service\SourceFilePrivacy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The file manager side of 3.6.2 (docs/dev/PLAN-source-file-privacy.md):
 *
 *  - the icons "move", "drag" and "delete" of a source file, a folder holding one and the
 *    protected folder are greyed out (drag: removed), with a tooltip naming the workflows.
 *    The refusal itself is SourceFileVoter's; without these icons a click would end on
 *    Contao's bare "Forbidden" page;
 *  - the checkbox "Öffentlich" of a folder holding a source file – or of anything in or above
 *    the protected folder – does not publish it.
 *
 * Both by wrapping the callbacks tl_files already has, captured when the DCA is loaded: Contao
 * processes the checkbox inside its input_field_callback (tl_files::protectFolder() reads the
 * POST while it renders – there is no save callback to hook into), and the icons come from
 * button_callbacks whose names differ between Contao versions (cutFile in 5.3, canRenameFile
 * in 5.7). Wrapping whatever is there keeps Contao's own checks (permissions, folder rules)
 * and only adds the refusal.
 *
 * The wrappers are [class, method] callbacks, not closures: Contao offers a field in "edit
 * multiple" only when its input_field_callback is an array (DC_Folder::editAll()), and it calls
 * a button_callback in the style its method signature asks for – hence one method per
 * operation and style. Resolved via System::importStatic(), so the service is public.
 *
 * Priority below DataContainerCallbackListener (-16), so a callback another bundle registers
 * is wrapped as well.
 */
#[AsHook('loadDataContainer', priority: -32)]
class FileManagerGuardListener
{
    /** Operation => what it would do to the path. */
    private const OPERATIONS = ['cut' => 'move', 'drag' => 'move', 'delete' => 'delete'];

    /** @var array<string, mixed> the callbacks of tl_files before wrapping, by operation ("protected" = the checkbox) */
    private array $originals = [];

    /** @var list<string> refusals of the checkbox in this request, see keepRefusalsAcrossReload() */
    private array $pendingRefusals = [];

    public function __construct(
        private readonly FileManagerGuard $guard,
        private readonly SourceFilePrivacy $privacy,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(string $table): void
    {
        if ('tl_files' !== $table) {
            return;
        }

        $dca = &$GLOBALS['TL_DCA']['tl_files'];

        foreach (array_keys(self::OPERATIONS) as $operation) {
            $original = $dca['list']['operations'][$operation]['button_callback'] ?? null;

            // Without a callback Contao renders the icon itself; there is nothing to wrap, and
            // the voter still refuses the action. Never wrap twice.
            if (null === $original || $this->isOwn($original) || !\is_array($dca['list']['operations'][$operation] ?? null)) {
                continue;
            }

            $this->originals[$operation] = $original;
            $style = $this->usesOperationObject($original) ? 'operation' : 'legacy';
            $dca['list']['operations'][$operation]['button_callback'] = [self::class, $style.ucfirst($operation)];
        }

        $publish = $dca['fields']['protected']['input_field_callback'] ?? null;

        if (null !== $publish && !$this->isOwn($publish)) {
            $this->originals['protected'] = $publish;
            $dca['fields']['protected']['input_field_callback'] = [self::class, 'protectedField'];
        }

        unset($dca);
    }

    /**
     * The checkbox "Öffentlich": when the POST would publish a folder that must stay protected,
     * the request value is cleared before Contao's own callback reads it – which then leaves
     * the folder as it is and renders the box unticked.
     */
    public function protectedField(DataContainer $dc, mixed ...$args): mixed
    {
        $path = trim(str_replace('\\', '/', (string) $dc->id), '/');

        if (
            'tl_files' === Input::post('FORM_SUBMIT')
            && Input::post($dc->inputName)
            && is_dir($this->projectDir.'/'.$path)
            // Already public (itself or a parent): there is nothing to publish, and unticking
            // must keep working.
            && null === $this->privacy->publicFolderOf($path)
        ) {
            $refusal = $this->guard->publishRefusal($path);

            if (null !== $refusal) {
                Input::setPost($dc->inputName, '');
                Message::addError($refusal);
                $this->pendingRefusals[] = $refusal;
            }
        }

        return $this->call($this->originals['protected'] ?? null, [$dc, ...$args]);
    }

    /**
     * The refusal of the checkbox has to survive the save. DC_Folder::edit() renders the fields
     * (this callback included), then calls Message::generate() for the page it is building –
     * which takes every message out of the session – and only then decides to reload. On a
     * reload that page is thrown away, and with it the refusal. So when the request ends in a
     * redirect, the refusal is added again for the page the redirect leads to. Without a
     * redirect (another field has an error, the form is shown again) it was shown already.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function keepRefusalsAcrossReload(ResponseEvent $event): void
    {
        if ([] === $this->pendingRefusals || !$event->isMainRequest()) {
            return;
        }

        if ($event->getResponse()->isRedirection()) {
            foreach ($this->pendingRefusals as $refusal) {
                Message::addError($refusal);
            }
        }

        $this->pendingRefusals = [];
    }

    public function legacyCut(mixed ...$args): string
    {
        return $this->legacyButton($args, 'cut');
    }

    public function legacyDrag(mixed ...$args): string
    {
        return $this->legacyButton($args, 'drag');
    }

    public function legacyDelete(mixed ...$args): string
    {
        return $this->legacyButton($args, 'delete');
    }

    public function operationCut(DataContainerOperation $config): void
    {
        $this->operationButton($config, 'cut');
    }

    public function operationDrag(DataContainerOperation $config): void
    {
        $this->operationButton($config, 'drag');
    }

    public function operationDelete(DataContainerOperation $config): void
    {
        $this->operationButton($config, 'delete');
    }

    /**
     * The 5.3 style: the callback returns the HTML of the icon.
     *
     * @param array<int, mixed> $args row, href, label, title, icon, attributes, table, …
     */
    private function legacyButton(array $args, string $operation): string
    {
        $refusal = $this->refusal((array) ($args[0] ?? []), self::OPERATIONS[$operation]);

        if (null === $refusal) {
            return (string) $this->call($this->originals[$operation] ?? null, $args);
        }

        return 'drag' === $operation ? ' ' : $this->disabledIcon((string) ($args[4] ?? ''), $refusal);
    }

    /**
     * The newer style: the callback changes the operation object.
     */
    private function operationButton(DataContainerOperation $config, string $operation): void
    {
        $this->call($this->originals[$operation] ?? null, [$config]);

        $refusal = $this->refusal($config->getRecord() ?? [], self::OPERATIONS[$operation]);

        if (null !== $refusal) {
            $config->setHtml('drag' === $operation ? '' : $this->disabledIcon((string) ($config['icon'] ?? ''), $refusal));
        }
    }

    /**
     * @param array<string, mixed> $row the file manager row ("id" = URL-encoded path)
     */
    private function refusal(array $row, string $action): ?string
    {
        $path = trim(rawurldecode((string) ($row['id'] ?? '')), '/');

        if ('' === $path) {
            return null;
        }

        return 'delete' === $action ? $this->guard->deleteRefusal($path) : $this->guard->moveRefusal($path);
    }

    /**
     * Contao's own look for an operation the user may not run: the "--disabled" variant of the
     * icon, not a link. Plus the reason as tooltip.
     */
    private function disabledIcon(string $icon, string $refusal): string
    {
        $icon = '' === $icon ? 'cut.svg' : $icon;

        if (!str_ends_with($icon, '--disabled.svg')) {
            $icon = str_replace('.svg', '--disabled.svg', $icon);
        }

        return Image::getHtml($icon, '', 'title="'.StringUtil::specialchars($refusal).'"').' ';
    }

    private function isOwn(mixed $callback): bool
    {
        return \is_array($callback) && self::class === ($callback[0] ?? null);
    }

    /**
     * Whether a button_callback takes the operation object (one parameter of that type) –
     * Contao decides the same way how to call it (DataContainer::generateButtons()). Reflected
     * by class name: the DCA is loaded outside the back end, too, where instantiating
     * tl_files would fail.
     */
    private function usesOperationObject(mixed $callback): bool
    {
        try {
            $reflection = \is_array($callback)
                ? new \ReflectionMethod((string) $callback[0], (string) $callback[1])
                : new \ReflectionFunction(\Closure::fromCallable($callback));
        } catch (\Throwable) {
            return false;
        }

        $type = 1 === $reflection->getNumberOfParameters() ? $reflection->getParameters()[0]->getType() : null;

        return $type instanceof \ReflectionNamedType && DataContainerOperation::class === $type->getName();
    }

    /**
     * Calls a DCA callback the way Contao does: [class, method] through System::importStatic(),
     * anything else as a callable.
     *
     * @param array<int, mixed> $args
     */
    private function call(mixed $callback, array $args): mixed
    {
        if (\is_array($callback) && isset($callback[0], $callback[1]) && \is_string($callback[0])) {
            return System::importStatic($callback[0])->{$callback[1]}(...$args);
        }

        return \is_callable($callback) ? $callback(...$args) : null;
    }
}
