<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\SourceFilePrivacy;

/**
 * Shared ground for the 3.6.2 source-file tests: a throw-away project directory with a
 * "files" folder, the two tables the privacy checks join (tl_workflow, tl_files) in SQLite, a
 * Contao framework whose adapters are plain callbacks, and the real German texts.
 */
abstract class SourceFileTestCase extends TestCase
{
    protected string $projectDir;

    protected Connection $connection;

    /** @var array<string, callable(string, array<int, mixed>): mixed> class => adapter behaviour */
    protected array $adapters = [];

    /** @var list<array{0: string, 1: string, 2: array<int, mixed>}> class, method, args */
    protected array $adapterCalls = [];

    /** @var array<string, mixed>|null */
    private ?array $previousLang = null;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/wf_privacy_'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/files', 0777, true);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_workflow (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', sourceFile BLOB NULL)");
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, path TEXT NOT NULL DEFAULT '')");

        // The whole file sets every section of the bundle; other tests rely on their texts
        // being absent (they assert the keys), so the previous state comes back in tearDown().
        // Found through the bundle's classes: the 5.3 run copies the tests elsewhere
        // (scripts/run-phpunit.sh), the sources stay where they are.
        $this->previousLang = $GLOBALS['TL_LANG'] ?? null;
        include \dirname((string) (new \ReflectionClass(SourceFilePrivacy::class))->getFileName(), 3).'/contao/languages/de/workflow_messages.php';
    }

    protected function tearDown(): void
    {
        $this->remove($this->projectDir);

        if (null === $this->previousLang) {
            unset($GLOBALS['TL_LANG']);
        } else {
            $GLOBALS['TL_LANG'] = $this->previousLang;
        }
    }

    /**
     * A workflow reading $path (the file need not exist).
     */
    protected function addWorkflow(int $id, string $title, string $path): void
    {
        $uuid = str_pad((string) $id, 16, 'u');
        $this->connection->insert('tl_files', ['id' => $id, 'uuid' => $uuid, 'path' => $path]);
        $this->connection->insert('tl_workflow', ['id' => $id, 'title' => $title, 'sourceFile' => $uuid]);
    }

    protected function touch(string $path, string $content = 'x'): void
    {
        $absolute = $this->projectDir.'/'.$path;

        if (!is_dir(\dirname($absolute))) {
            mkdir(\dirname($absolute), 0777, true);
        }

        file_put_contents($absolute, $content);
    }

    protected function publish(string $folder): void
    {
        $this->touch($folder.'/.public', '');
    }

    protected function framework(): ContaoFramework
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(function (string $class): Adapter {
            $adapter = $this->createMock(Adapter::class);
            $adapter->method('__call')->willReturnCallback(function (string $method, array $args) use ($class): mixed {
                $this->adapterCalls[] = [$class, $method, $args];
                $behaviour = $this->adapters[$class] ?? null;

                return null !== $behaviour ? $behaviour($method, $args) : null;
            });

            return $adapter;
        });

        return $framework;
    }

    protected function privacy(): SourceFilePrivacy
    {
        return new SourceFilePrivacy($this->framework(), $this->connection, $this->projectDir, 'files');
    }

    protected function filesModel(string $path, string $uuid = 'uuid-of-a-file!!'): FilesModel
    {
        $model = $this->createMock(FilesModel::class);
        $model->method('__get')->willReturnCallback(static fn (string $key): mixed => ['path' => $path, 'uuid' => $uuid][$key] ?? null);

        return $model;
    }

    /**
     * @return list<array<int, mixed>> the arguments of every call of $class::$method
     */
    protected function callsOf(string $class, string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call[2],
            array_filter($this->adapterCalls, static fn (array $call): bool => $call[0] === $class && $call[1] === $method),
        ));
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->remove($path.'/'.$entry);
                }
            }

            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }
}
