<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\EventListener\DataContainer\ChildListGuardListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Contao's own lists of the answer fields and document texts must stay closed: only the
 * single-record actions of the embedded lists pass, everything else leaves the dialog or
 * goes to the workflow mask. Under Contao 5.7 "back" in a dialog opened the sortable list,
 * whose drag&drop wrote the order past the workflow's version.
 */
final class ChildListGuardListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE tl_workflow (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE tl_workflow_question (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE tl_workflow_rule (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL)');
        $this->connection->executeStatement('INSERT INTO tl_workflow (id) VALUES (61)');
        $this->connection->executeStatement('INSERT INTO tl_workflow_question (id, pid) VALUES (392, 61)');
        $this->connection->executeStatement('INSERT INTO tl_workflow_rule (id, pid) VALUES (126, 61)');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function singleRecordActions(): iterable
    {
        yield 'edit' => ['edit'];
        yield 'create' => ['create'];
        yield 'delete' => ['delete'];
        yield 'show' => ['show'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('singleRecordActions')]
    public function testSingleRecordActionsPass(string $action): void
    {
        $this->guard('questions', ['table' => 'tl_workflow_question', 'act' => $action, 'id' => '392', 'popup' => '1']);

        $this->addToAssertionCount(1);
    }

    public function testListInADialogClosesTheDialog(): void
    {
        $response = $this->expectResponse('questions', ['table' => 'tl_workflow_question', 'id' => '61', 'popup' => '1']);

        $this->assertStringContainsString("window.top.postMessage('closeModal', '*')", $response);
        // Outside a frame (a delete returns to this URL in the main window) it goes to the mask.
        $url = json_encode('/contao?do=workflow_manage&act=edit&id=61', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->assertStringContainsString('window.location.replace('.$url.')', $response);
    }

    public function testListOutsideADialogRedirectsToTheWorkflowMask(): void
    {
        $this->assertSame('/contao?do=workflow_manage&act=edit&id=61', $this->expectRedirect('rules', ['table' => 'tl_workflow_rule', 'id' => '61']));
    }

    public function testEditMultipleRedirectsToTheWorkflowMask(): void
    {
        $this->assertSame('/contao?do=workflow_manage&act=edit&id=61', $this->expectRedirect('questions', ['table' => 'tl_workflow_question', 'act' => 'select', 'id' => '61']));
    }

    public function testRecordActionsFindTheWorkflowThroughTheRecord(): void
    {
        // For cut/copy/paste "id" is the child record, not the workflow.
        $this->assertSame('/contao?do=workflow_manage&act=edit&id=61', $this->expectRedirect('questions', ['table' => 'tl_workflow_question', 'act' => 'cut', 'id' => '392']));
        $this->assertSame('/contao?do=workflow_manage&act=edit&id=61', $this->expectRedirect('rules', ['table' => 'tl_workflow_rule', 'act' => 'copy', 'id' => '126']));
    }

    public function testUnknownWorkflowFallsBackToTheWorkflowList(): void
    {
        $this->assertSame('/contao?do=workflow_manage', $this->expectRedirect('questions', ['table' => 'tl_workflow_question', 'id' => '999']));
    }

    public function testOtherTablesAreLeftAlone(): void
    {
        // The callback is registered per table, but the guard checks the request as well.
        $this->guard('questions', ['table' => 'tl_workflow_entry', 'id' => '61']);

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, string> $query
     */
    private function guard(string $list, array $query): void
    {
        $stack = new RequestStack();
        $stack->push(new Request($query));

        $listener = new ChildListGuardListener($stack, $this->router(), $this->connection);

        'rules' === $list ? $listener->guardRules() : $listener->guardQuestions();
    }

    /**
     * @param array<string, string> $query
     */
    private function expectResponse(string $list, array $query): string
    {
        try {
            $this->guard($list, $query);
        } catch (ResponseException $e) {
            return (string) $e->getResponse()->getContent();
        }

        $this->fail('Expected the dialog to be closed.');
    }

    /**
     * @param array<string, string> $query
     */
    private function expectRedirect(string $list, array $query): string
    {
        try {
            $this->guard($list, $query);
        } catch (RedirectResponseException $e) {
            return (string) $e->getResponse()->headers->get('Location');
        }

        $this->fail('Expected a redirect to the workflow mask.');
    }

    private function router(): RouterInterface
    {
        return new class() implements RouterInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/contao?'.http_build_query($parameters);
            }

            public function getRouteCollection(): RouteCollection
            {
                return new RouteCollection();
            }

            public function match(string $pathinfo): array
            {
                return [];
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
