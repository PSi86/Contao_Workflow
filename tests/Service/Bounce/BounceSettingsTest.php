<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceCollector;
use Psimandl\WorkflowBundle\Service\Bounce\BounceMailbox;
use Psimandl\WorkflowBundle\Service\Bounce\BounceSettings;
use Psimandl\WorkflowBundle\Service\Bounce\PasswordCipher;

/**
 * The bounce mailbox set up in the back end, and how it combines with .env.local: the
 * environment wins (decision E5), the password is stored encrypted (E10).
 */
final class BounceSettingsTest extends TestCase
{
    private const VALUES = [
        'address' => 'workflow-test@tsvkorntal.de',
        'host' => 'w020341b.kasserver.com',
        'port' => 993,
        'encryption' => 'ssl',
        'user' => 'workflow-test@tsvkorntal.de',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        if (!PasswordCipher::isAvailable()) {
            self::markTestSkipped('ext-sodium is not available.');
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_settings (
                id              INTEGER PRIMARY KEY,
                tstamp          INTEGER NOT NULL DEFAULT 0,
                bounceAddress   TEXT NOT NULL DEFAULT '',
                imapHost        TEXT NOT NULL DEFAULT '',
                imapPort        INTEGER NOT NULL DEFAULT 993,
                imapEncryption  TEXT NOT NULL DEFAULT 'ssl',
                imapUser        TEXT NOT NULL DEFAULT '',
                imapPasswordEnc TEXT NULL
            )
            SQL);
    }

    public function testSavedPasswordIsEncryptedAndReadBack(): void
    {
        $settings = $this->settings();
        $settings->save(self::VALUES, 'Q9b+ND3 RL%zx');

        $stored = (string) $this->connection->fetchOne('SELECT imapPasswordEnc FROM tl_workflow_settings');
        self::assertStringNotContainsString('Q9b', $stored);

        $read = $settings->read();
        self::assertSame('Q9b+ND3 RL%zx', $read['password']);
        self::assertTrue($read['passwordSet']);
        self::assertSame('w020341b.kasserver.com', $read['host']);
    }

    public function testSavingWithoutPasswordKeepsTheStoredOne(): void
    {
        $settings = $this->settings();
        $settings->save(self::VALUES, 'first');
        $settings->save(['port' => 143, 'encryption' => 'tls'] + self::VALUES, null);

        $read = $settings->read();
        self::assertSame('first', $read['password']);
        self::assertSame(143, $read['port']);
        self::assertSame('tls', $read['encryption']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_workflow_settings'));
    }

    public function testNothingSetUpAndClear(): void
    {
        $settings = $this->settings();
        self::assertNull($settings->read());

        $settings->save(self::VALUES, 'pw');
        $settings->clear();

        self::assertNull($settings->read());
    }

    public function testMissingTableMeansNothingSetUp(): void
    {
        $this->connection->executeStatement('DROP TABLE tl_workflow_settings');

        self::assertNull($this->settings()->read());
        self::assertNull((new BounceMailbox(null, null, $this->settings()))->source());
    }

    public function testValidate(): void
    {
        self::assertSame([], BounceSettings::validate(self::VALUES, true, false));
        self::assertSame([], BounceSettings::validate(self::VALUES, false, true), 'a stored password may be kept');
        self::assertSame(['mailbox_err_password'], BounceSettings::validate(self::VALUES, false, false));
        self::assertSame(
            ['mailbox_err_address', 'mailbox_err_host', 'mailbox_err_port', 'mailbox_err_encryption', 'mailbox_err_user'],
            BounceSettings::validate(['address' => 'nope', 'host' => 'bad host', 'port' => 0, 'encryption' => 'starttls', 'user' => ''], true, false),
        );
    }

    public function testBackendSettingsServeTheCollectorAndTheEnvelope(): void
    {
        $this->settings()->save(self::VALUES, 'pa+ss@word');
        $mailbox = new BounceMailbox(null, null, $this->settings());

        self::assertSame(BounceMailbox::SOURCE_BACKEND, $mailbox->source());
        self::assertSame('workflow-test@tsvkorntal.de', $mailbox->address());

        $config = BounceCollector::imapConfig($mailbox->imapDsn());
        self::assertSame('w020341b.kasserver.com', $config['host']);
        self::assertSame(993, $config['port']);
        self::assertSame('ssl', $config['encryption']);
        self::assertSame('workflow-test@tsvkorntal.de', $config['username']);
        self::assertSame('pa+ss@word', $config['password']);
    }

    public function testMailboxNameAsUserTakesTheAddressField(): void
    {
        $this->settings()->save(['user' => 'm0836d36'] + self::VALUES, 'pw');

        self::assertSame('workflow-test@tsvkorntal.de', (new BounceMailbox(null, null, $this->settings()))->address());
    }

    public function testEnvironmentTakesPrecedence(): void
    {
        $this->settings()->save(self::VALUES, 'pw');
        $mailbox = new BounceMailbox('imap://workflow%40tsvkorntal.de:x@w0123456.kasserver.com:993', null, $this->settings());

        self::assertSame(BounceMailbox::SOURCE_ENV, $mailbox->source());
        self::assertSame('workflow@tsvkorntal.de', $mailbox->address());
        self::assertStringContainsString('w0123456.kasserver.com', $mailbox->imapDsn());
        self::assertSame(['host' => 'w0123456.kasserver.com', 'port' => 993, 'user' => 'workflow@tsvkorntal.de'], $mailbox->envSummary());
        self::assertNotNull($mailbox->backend(), 'the back-end values stay visible');
    }

    public function testExplicitAddressWinsOverTheBackend(): void
    {
        $this->settings()->save(self::VALUES, 'pw');

        self::assertSame('bounces@example.org', (new BounceMailbox(null, 'bounces@example.org', $this->settings()))->address());
    }

    /**
     * APP_SECRET changed: the mailbox cannot be read, which the overview says – but the address
     * still routes bounces into the mailbox, where they wait until the password is entered.
     */
    public function testUnreadablePasswordAfterAppSecretChange(): void
    {
        $this->settings()->save(self::VALUES, 'pw');
        $mailbox = new BounceMailbox(null, null, $this->settings('another-app-secret'));

        self::assertTrue($mailbox->passwordUnreadable());
        self::assertSame('', $mailbox->imapDsn());
        self::assertSame('workflow-test@tsvkorntal.de', $mailbox->address());
        self::assertSame(BounceMailbox::SOURCE_BACKEND, $mailbox->source());
    }

    private function settings(string $appSecret = 'app-secret-1'): BounceSettings
    {
        return new BounceSettings($this->connection, new PasswordCipher($appSecret));
    }
}
