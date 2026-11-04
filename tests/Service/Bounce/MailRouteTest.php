<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use Contao\CoreBundle\Mailer\AvailableTransports;
use Contao\CoreBundle\Mailer\TransportConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceMailbox;
use Psimandl\WorkflowBundle\Service\Bounce\MailRoute;

/**
 * Sender, replies and bounces of a notification as the edit mask shows them, and the warnings
 * about them (decision E6: neither sender nor Reply-To may be the bounce mailbox).
 */
final class MailRouteTest extends TestCase
{
    private const BOUNCE_DSN = 'imap://workflow%40tsvkorntal.de:x@w0123456.kasserver.com:993';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_nc_gateway (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT '', mailerTransport TEXT NULL)");
        $this->connection->executeStatement("CREATE TABLE tl_nc_message (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, sorting INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT '', gateway INTEGER NOT NULL DEFAULT 0, published TEXT NOT NULL DEFAULT '')");
        $this->connection->executeStatement("CREATE TABLE tl_nc_language (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, fallback TEXT NOT NULL DEFAULT '', email_sender_address TEXT NOT NULL DEFAULT '', email_replyTo TEXT NULL)");
        $this->connection->executeStatement("CREATE TABLE tl_nc_notification (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '')");

        $this->connection->insert('tl_nc_gateway', ['id' => 1, 'title' => 'E-Mail (Workflow)', 'type' => 'mailer', 'mailerTransport' => 'workflow']);
        $this->connection->insert('tl_nc_gateway', ['id' => 2, 'title' => 'E-Mail', 'type' => 'mailer', 'mailerTransport' => null]);
        $this->connection->insert('tl_nc_gateway', ['id' => 3, 'title' => 'Slack', 'type' => 'slack']);
    }

    public function testForcedTransportSenderReplyToAndBounceMailbox(): void
    {
        $this->message(10, gateway: 1, sender: 'irgendwer@gmail.com', replyTo: 'verwaltung@tsvkorntal.de');

        $route = $this->route()->forNotification(10)[0];

        self::assertSame('verwaltung@tsvkorntal.de', $route['from'], 'the transport forces From, whatever the notification says');
        self::assertSame(MailRoute::FROM_TRANSPORT, $route['fromSource']);
        self::assertSame('workflow', $route['transport']);
        self::assertSame('verwaltung@tsvkorntal.de', $route['answersTo']);
        self::assertSame('workflow@tsvkorntal.de', $route['bounceTo']);
        self::assertTrue($route['bounceToMailbox']);
    }

    public function testWithoutTransportTheNotificationSenderAndNoMailbox(): void
    {
        $this->message(20, gateway: 2, sender: 'noreply@tsvkorntal.com', replyTo: null);

        $route = $this->route(new BounceMailbox())->forNotification(20)[0];

        self::assertSame('noreply@tsvkorntal.com', $route['from']);
        self::assertSame(MailRoute::FROM_NOTIFICATION, $route['fromSource']);
        self::assertSame('noreply@tsvkorntal.com', $route['answersTo'], 'replies go to the sender without Reply-To');
        self::assertSame('noreply@tsvkorntal.com', $route['bounceTo'], 'bounces go to the sender without a bounce address');
        self::assertFalse($route['bounceToMailbox']);
    }

    public function testEmptySenderMeansAdminAddressAndUnpublishedMessagesAreIgnored(): void
    {
        $this->message(30, gateway: 2, sender: '', replyTo: null);
        $this->message(30, gateway: 3, sender: '', replyTo: null, published: '');

        $routes = $this->route()->forNotification(30);

        self::assertCount(1, $routes);
        self::assertSame(MailRoute::FROM_ADMIN, $routes[0]['fromSource']);
    }

    public function testWarnings(): void
    {
        $this->message(40, gateway: 2, sender: 'Workflow@TSVKorntal.de', replyTo: 'workflow@tsvkorntal.de');
        $this->message(41, gateway: 3, sender: '', replyTo: null);

        $routes = [...$this->route()->forNotification(40), ...$this->route()->forNotification(41)];

        self::assertSame(
            ['route_from_is_bounce', 'route_replyto_is_bounce', 'route_not_mail'],
            array_column(MailRoute::warnings($routes, 'workflow@tsvkorntal.de', true, false), 'key'),
        );

        // A notification that sends no mail at all does not need a bounce mailbox.
        self::assertSame(
            ['route_not_mail'],
            array_column(MailRoute::warnings($this->route()->forNotification(41), null, false, false), 'key'),
        );
    }

    public function testMailboxStateWarningsAndTheTargetSetup(): void
    {
        // Target setup of tsvkorntal.de (E9/E13): no warning at all.
        $this->message(50, gateway: 1, sender: '', replyTo: null);
        $routes = $this->route()->forNotification(50);

        self::assertSame([], MailRoute::warnings($routes, 'workflow@tsvkorntal.de', true, false));
        self::assertSame(['route_no_mailbox'], array_column(MailRoute::warnings($routes, null, false, false), 'key'));
        self::assertSame(['route_mailbox_failing'], array_column(MailRoute::warnings($routes, 'workflow@tsvkorntal.de', true, true), 'key'));
    }

    public function testATokenIsNeverTakenForTheBounceAddress(): void
    {
        $this->message(60, gateway: 2, sender: '##admin_email##', replyTo: null);

        self::assertSame([], MailRoute::warnings($this->route()->forNotification(60), '##admin_email##', true, false));
    }

    public function testForNotificationsKeepsEachNotificationApart(): void
    {
        $this->message(70, gateway: 2, sender: 'a@tsvkorntal.de', replyTo: null);
        $this->message(70, gateway: 3, sender: '', replyTo: null);
        $this->message(71, gateway: 2, sender: 'b@tsvkorntal.de', replyTo: 'r@tsvkorntal.de');
        $this->message(72, gateway: 2, sender: 'c@tsvkorntal.de', replyTo: null, published: '');

        $routes = $this->route()->forNotifications([71, 70, 72, 0, -3, 70]);

        self::assertSame([70, 71], array_keys($routes), 'a notification without a published message has no key');
        self::assertSame(['a@tsvkorntal.de', ''], array_column($routes[70], 'from'), 'the messages of a notification in their order');
        self::assertSame([true, false], array_column($routes[70], 'mail'));
        self::assertSame('r@tsvkorntal.de', $routes[71][0]['answersTo']);
        self::assertSame($routes[70], $this->route()->forNotification(70));
        self::assertSame([], $this->route()->forNotifications([0, -1]));
    }

    public function testTheFallbackLanguageWinsOverAnOlderOne(): void
    {
        $this->message(75, gateway: 2, sender: 'alt@tsvkorntal.de', replyTo: null, fallback: '');
        $messageId = (int) $this->connection->fetchOne('SELECT MAX(id) FROM tl_nc_message');
        $this->connection->insert('tl_nc_language', ['pid' => $messageId, 'fallback' => '1', 'email_sender_address' => 'fallback@tsvkorntal.de', 'email_replyTo' => null]);

        $this->message(76, gateway: 2, sender: 'erste@tsvkorntal.de', replyTo: null, fallback: '');
        $messageId = (int) $this->connection->fetchOne('SELECT MAX(id) FROM tl_nc_message');
        $this->connection->insert('tl_nc_language', ['pid' => $messageId, 'fallback' => '', 'email_sender_address' => 'zweite@tsvkorntal.de', 'email_replyTo' => null]);

        $routes = $this->route()->forNotifications([75, 76]);

        self::assertSame('fallback@tsvkorntal.de', $routes[75][0]['from']);
        self::assertSame('erste@tsvkorntal.de', $routes[76][0]['from'], 'without a fallback language the oldest one');
    }

    public function testNotificationIdsListsEveryNotification(): void
    {
        foreach ([3, 1, 2] as $id) {
            $this->connection->insert('tl_nc_notification', ['id' => $id, 'title' => 'N'.$id]);
        }

        self::assertSame([1, 2, 3], $this->route()->notificationIds());

        $this->connection->executeStatement('DROP TABLE tl_nc_notification');

        self::assertSame([], $this->route()->notificationIds(), 'without the Notification Center: none');
    }

    /**
     * The edit mask shows a warning while one of its choices is selected. For every selection
     * of the three fields that must be exactly what warnings() says about the selected routes.
     */
    public function testWarningsByChoiceGiveTheWarningsOfEverySelection(): void
    {
        $bounce = 'workflow@tsvkorntal.de';
        $this->message(80, gateway: 2, sender: $bounce, replyTo: null);
        $this->message(81, gateway: 2, sender: 'verwaltung@tsvkorntal.de', replyTo: $bounce);
        $this->message(82, gateway: 3, sender: '', replyTo: null);
        $this->message(83, gateway: 2, sender: 'verwaltung@tsvkorntal.de', replyTo: null);

        $byId = $this->route()->forNotifications([80, 81, 82, 83]);
        $kinds = ['invite', 'reminder', 'result'];
        $routesByChoice = [];

        foreach ($kinds as $kind) {
            foreach ($byId as $id => $routes) {
                $routesByChoice[$kind.':'.$id] = $routes;
            }
        }

        $options = [0, 80, 81, 82, 83];
        $ids = static fn (array $warnings): array => array_map(static fn (array $w): string => $w['key'].'|'.implode('|', $w['args']), array_values($warnings));
        $mailboxKeys = ['route_no_mailbox', 'route_mailbox_failing'];

        foreach ([[true, false], [false, false], [true, true]] as [$configured, $failing]) {
            $byChoice = MailRoute::warningsByChoice($routesByChoice, $bounce, $configured, $failing);

            $keys = array_column($byChoice, 'key');
            $isMailbox = array_map(static fn (string $key): bool => \in_array($key, $mailboxKeys, true), $keys);
            self::assertSame($isMailbox, [...array_filter($isMailbox, static fn (bool $b): bool => !$b), ...array_filter($isMailbox)], 'the mailbox warnings come last');

            foreach ($options as $invite) {
                foreach ($options as $reminder) {
                    foreach ($options as $result) {
                        $selected = ['invite:'.$invite, 'reminder:'.$reminder, 'result:'.$result];
                        $routes = [...$byId[$invite] ?? [], ...$byId[$reminder] ?? [], ...$byId[$result] ?? []];

                        $expected = $ids(MailRoute::warnings($routes, $bounce, $configured, $failing));
                        $shown = $ids(array_filter($byChoice, static fn (array $w): bool => [] !== array_intersect($w['choices'], $selected)));
                        sort($expected);
                        sort($shown);

                        self::assertSame($expected, $shown, implode(' ', $selected).($configured ? '' : ', no mailbox').($failing ? ', failing' : ''));
                    }
                }
            }
        }
    }

    private function route(?BounceMailbox $mailbox = null): MailRoute
    {
        $transports = new AvailableTransports();
        $transports->addTransport(new TransportConfig('workflow', 'TSV Korntal <verwaltung@tsvkorntal.de>'));

        return new MailRoute($this->connection, $mailbox ?? new BounceMailbox(self::BOUNCE_DSN), $transports);
    }

    private function message(int $notification, int $gateway, string $sender, ?string $replyTo, string $published = '1', string $fallback = '1'): void
    {
        static $id = 100;
        ++$id;

        $this->connection->insert('tl_nc_message', ['id' => $id, 'pid' => $notification, 'title' => 'Nachricht '.$id, 'gateway' => $gateway, 'published' => $published]);
        $this->connection->insert('tl_nc_language', ['pid' => $id, 'fallback' => $fallback, 'email_sender_address' => $sender, 'email_replyTo' => $replyTo]);
    }
}
