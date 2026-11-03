<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Keeps the answer fields and document texts to the lists embedded in the workflow mask.
 *
 * Both tables are edited record by record in a dcaWizard dialog. Contao also has its own
 * list of each table (parent view), with drag&drop, cut/paste, "edit multiple" and
 * copying – a second way to change the workflow that writes at once and leaves no version
 * of the workflow. Under Contao 5.3, dcaWizard closed the dialog whenever it was about to
 * show that list ("back"); dcaWizard 3.1 dropped that, and Contao 5.7 builds the back link
 * itself, so "back" in the dialog opened the list instead.
 *
 * Only the single-record actions pass. Everything else ends where it belongs: inside a
 * dialog the dialog closes, otherwise the browser goes to the workflow mask.
 */
class ChildListGuardListener
{
    /**
     * Actions on one record, as the embedded lists and their dialogs use them.
     */
    private const ALLOWED_ACTIONS = ['edit', 'create', 'delete', 'show'];

    /**
     * Actions whose "id" is the parent workflow (the list and its "edit multiple" modes);
     * for all others it is a record of the child table.
     */
    private const PARENT_ID_ACTIONS = ['', 'select', 'editAll', 'overrideAll', 'deleteAll', 'copyAll', 'cutAll'];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly Connection $connection,
    ) {
    }

    #[AsCallback(table: 'tl_workflow_question', target: 'config.onload')]
    public function guardQuestions(): void
    {
        $this->guard('tl_workflow_question');
    }

    #[AsCallback(table: 'tl_workflow_rule', target: 'config.onload')]
    public function guardRules(): void
    {
        $this->guard('tl_workflow_rule');
    }

    private function guard(string $table): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || $table !== $request->query->get('table')) {
            return;
        }

        $action = (string) $request->query->get('act', '');

        if (\in_array($action, self::ALLOWED_ACTIONS, true)) {
            return;
        }

        $target = $this->workflowMaskUrl($this->workflowId($table, $action, $request));

        if ($request->query->has('popup')) {
            throw new ResponseException($this->leaveDialog($target));
        }

        throw new RedirectResponseException($target);
    }

    /**
     * A dialog closes (dcaWizard listens for the same message it sends itself). The same
     * URL can also arrive in the main window – a delete runs there and returns to its
     * referer – so outside a frame the page goes to the workflow mask instead.
     */
    private function leaveDialog(string $target): Response
    {
        $url = json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return new Response(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><script>'
            ."if (window.top !== window.self) { window.top.postMessage('closeModal', '*'); } else { window.location.replace($url); }"
            .'</script></body></html>',
        );
    }

    private function workflowId(string $table, string $action, Request $request): int
    {
        $id = (int) $request->query->get('id', 0);

        if ($id < 1) {
            return 0;
        }

        if (\in_array($action, self::PARENT_ID_ACTIONS, true)) {
            return $id;
        }

        return (int) $this->connection->fetchOne("SELECT pid FROM $table WHERE id = ?", [$id]);
    }

    private function workflowMaskUrl(int $workflowId): string
    {
        $exists = $workflowId > 0
            && false !== $this->connection->fetchOne('SELECT id FROM tl_workflow WHERE id = ?', [$workflowId]);

        $params = $exists
            ? ['do' => 'workflow_manage', 'act' => 'edit', 'id' => $workflowId]
            : ['do' => 'workflow_manage'];

        return $this->router->generate('contao_backend', $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
