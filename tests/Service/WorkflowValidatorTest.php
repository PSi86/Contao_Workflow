<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\LinkGenerator;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;

/**
 * The sender-domain warnings (AP7) target the root cause of silently lost bounces: a sender
 * whose domain is undeliverable (no MX) or does not match the website. The DNS lookup is
 * overridden here so the logic is tested without live DNS. Warning strings come back as their
 * bare language keys because no Contao language file is loaded in a unit test — enough to
 * assert which warning fired.
 */
final class WorkflowValidatorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement('CREATE TABLE tl_nc_notification (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE tl_nc_message (id INTEGER PRIMARY KEY, pid INTEGER)');
        $this->connection->executeStatement('CREATE TABLE tl_nc_language (id INTEGER PRIMARY KEY, pid INTEGER, fallback INTEGER, email_sender_address TEXT)');
        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', dns TEXT NOT NULL DEFAULT '')");
    }

    public function testSenderDomainWithoutMxWarns(): void
    {
        $this->addNotification(1, 'noreply@typo-domain-xyz.de');

        self::assertSame(['sender_no_mx'], $this->validator(mxDomains: [])->senderWarnings([1, 0, 0]));
    }

    public function testPlaceholderDomainWarnsEvenWhenItHasAnMx(): void
    {
        $this->addNotification(1, 'noreply@example.com');

        // example.com resolves and even carries an MX record (IANA), so it must be caught by
        // the explicit placeholder check, not the MX check — regardless of the MX result.
        self::assertSame(['sender_placeholder'], $this->validator(mxDomains: ['example.com'])->senderWarnings([1]));
    }

    public function testMatchingDomainWithMxDoesNotWarn(): void
    {
        $this->addRootPage('mysite.com');
        $this->addNotification(1, 'noreply@mysite.com');

        self::assertSame([], $this->validator(mxDomains: ['mysite.com'])->senderWarnings([1]));
    }

    public function testSubdomainOfSiteDomainIsAccepted(): void
    {
        $this->addRootPage('mysite.com');
        $this->addNotification(1, 'noreply@mail.mysite.com');

        self::assertSame([], $this->validator(mxDomains: ['mail.mysite.com'])->senderWarnings([1]));
    }

    public function testDeliverableButForeignDomainWarnsAboutMismatch(): void
    {
        $this->addRootPage('mysite.com');
        $this->addNotification(1, 'noreply@somewhere-else.org');

        self::assertSame(['sender_domain_mismatch'], $this->validator(mxDomains: ['somewhere-else.org'])->senderWarnings([1]));
    }

    public function testEmptySenderIsIgnored(): void
    {
        $this->addNotification(1, '');

        self::assertSame([], $this->validator(mxDomains: [])->senderWarnings([1]));
    }

    public function testTheSameBadDomainWarnsOnlyOnce(): void
    {
        $this->addNotification(1, 'invite@typo-domain-xyz.de');
        $this->addNotification(2, 'reminder@typo-domain-xyz.de');

        self::assertSame(['sender_no_mx'], $this->validator(mxDomains: [])->senderWarnings([1, 2, 0]));
    }

    private function addNotification(int $id, string $sender): void
    {
        $this->connection->insert('tl_nc_notification', ['id' => $id]);
        $this->connection->insert('tl_nc_message', ['id' => $id * 10, 'pid' => $id]);
        $this->connection->insert('tl_nc_language', ['id' => $id * 100, 'pid' => $id * 10, 'fallback' => 1, 'email_sender_address' => $sender]);
    }

    private function addRootPage(string $dns): void
    {
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'dns' => $dns]);
    }

    /**
     * @param array<int, string> $mxDomains domains that "have" an MX record
     */
    private function validator(array $mxDomains): WorkflowValidator
    {
        return new class($this->createMock(SpreadsheetInspector::class), $this->createMock(LinkGenerator::class), $this->connection, $mxDomains) extends WorkflowValidator {
            /**
             * @param array<int, string> $mxDomains
             */
            public function __construct(SpreadsheetInspector $inspector, LinkGenerator $linkGenerator, Connection $connection, private readonly array $mxDomains)
            {
                parent::__construct($inspector, $linkGenerator, $connection);
            }

            protected function hasMxRecord(string $domain): bool
            {
                return \in_array($domain, $this->mxDomains, true);
            }
        };
    }
}
