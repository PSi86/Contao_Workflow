<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Contao\CoreBundle\Mailer\AvailableTransports;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mime\Address;

/**
 * Where the mails of a Notification Center notification really go: what recipients see as
 * sender, where their replies go, and where bounces go – the three addresses that 3.6.0
 * separates. Shown on the workflow edit mask next to the notification fields.
 *
 *  - Sender: a "from" forced on the gateway's mailer transport wins (ContaoMailer::setFrom());
 *    otherwise the notification's sender address; empty means the Notification Center uses
 *    ##admin_email##.
 *  - Replies: Reply-To, otherwise the sender.
 *  - Bounces: the bounce mailbox address ({@see BounceMailbox}, set as envelope sender by
 *    EnvelopeSenderListener); without one the mailer derives the envelope sender from the
 *    sender, so bounces go there.
 */
class MailRoute
{
    public const FROM_TRANSPORT = 'transport';

    public const FROM_NOTIFICATION = 'notification';

    public const FROM_ADMIN = 'admin';

    public function __construct(
        private readonly Connection $connection,
        private readonly BounceMailbox $mailbox,
        private readonly ?AvailableTransports $transports = null,
    ) {
    }

    /**
     * One route per published message of the notification.
     *
     * @return list<array{message: string, gateway: string, mail: bool, transport: string, from: string, fromSource: string, replyTo: string, answersTo: string, bounceTo: string, bounceToMailbox: bool}>
     */
    public function forNotification(int $notificationId): array
    {
        return $this->forNotifications([$notificationId])[$notificationId] ?? [];
    }

    /**
     * Every notification the edit mask offers: its notification fields list them all
     * (foreignKey tl_nc_notification.title).
     *
     * @return list<int>
     */
    public function notificationIds(): array
    {
        try {
            return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM tl_nc_notification ORDER BY id'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The routes of several notifications, keyed by notification id; a notification without a
     * published message has no key. Two queries however many notifications there are – the
     * edit mask needs the route of every notification the user can pick.
     *
     * @param list<int> $notificationIds
     *
     * @return array<int, list<array{message: string, gateway: string, mail: bool, transport: string, from: string, fromSource: string, replyTo: string, answersTo: string, bounceTo: string, bounceToMailbox: bool}>>
     */
    public function forNotifications(array $notificationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $notificationIds), static fn (int $id): bool => $id > 0)));

        if ([] === $ids) {
            return [];
        }

        try {
            $messages = $this->connection->fetchAllAssociative(
                "SELECT m.id, m.pid, m.title, g.title AS gatewayTitle, g.type, g.mailerTransport
                   FROM tl_nc_message m
                   LEFT JOIN tl_nc_gateway g ON g.id = m.gateway
                  WHERE m.pid IN (?) AND m.published = '1'
                  ORDER BY m.pid, m.sorting, m.id",
                [$ids],
                [ArrayParameterType::INTEGER],
            );

            $mailIds = [];

            foreach ($messages as $message) {
                if ('mailer' === (string) ($message['type'] ?? '')) {
                    $mailIds[] = (int) $message['id'];
                }
            }

            // Per message the fallback language, otherwise the oldest one.
            $languages = [];

            if ([] !== $mailIds) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT pid, email_sender_address, email_replyTo FROM tl_nc_language WHERE pid IN (?) ORDER BY pid, fallback DESC, id',
                    [$mailIds],
                    [ArrayParameterType::INTEGER],
                );

                foreach ($rows as $row) {
                    $languages[(int) $row['pid']] ??= $row;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        $bounce = $this->mailbox->address();
        $routes = [];

        foreach ($messages as $message) {
            $route = [
                'message' => (string) $message['title'],
                'gateway' => (string) ($message['gatewayTitle'] ?? ''),
                'mail' => 'mailer' === (string) ($message['type'] ?? ''),
                'transport' => trim((string) ($message['mailerTransport'] ?? '')),
                'from' => '',
                'fromSource' => self::FROM_ADMIN,
                'replyTo' => '',
                'answersTo' => '',
                'bounceTo' => '',
                'bounceToMailbox' => false,
            ];

            $notification = (int) $message['pid'];

            if (!$route['mail']) {
                $routes[$notification][] = $route;

                continue;
            }

            $language = $languages[(int) $message['id']] ?? [];

            $forced = '' !== $route['transport'] ? $this->transports?->getTransport($route['transport'])?->getFrom() : null;
            $sender = trim((string) ($language['email_sender_address'] ?? ''));

            if (null !== $forced && '' !== trim($forced)) {
                $route['from'] = self::addressOf($forced);
                $route['fromSource'] = self::FROM_TRANSPORT;
            } elseif ('' !== $sender) {
                $route['from'] = $sender;
                $route['fromSource'] = self::FROM_NOTIFICATION;
            }

            $route['replyTo'] = trim((string) ($language['email_replyTo'] ?? ''));
            $route['answersTo'] = '' !== $route['replyTo'] ? $route['replyTo'] : $route['from'];
            $route['bounceToMailbox'] = null !== $bounce;
            $route['bounceTo'] = $bounce ?? $route['from'];

            $routes[$notification][] = $route;
        }

        return $routes;
    }

    /**
     * What is wrong with a set of routes, as language keys with their arguments. Pure.
     *
     * @param list<array{message: string, gateway: string, mail: bool, transport: string, from: string, fromSource: string, replyTo: string, answersTo: string, bounceTo: string, bounceToMailbox: bool}> $routes
     *
     * @return list<array{key: string, args: list<string>}>
     */
    public static function warnings(array $routes, ?string $bounceAddress, bool $mailboxConfigured, bool $mailboxFailing): array
    {
        $warnings = [];
        $add = static function (string $key, string ...$args) use (&$warnings): void {
            $warnings[$key.'|'.implode('|', $args)] = ['key' => $key, 'args' => $args];
        };

        $anyMail = false;

        foreach ($routes as $route) {
            if (!$route['mail']) {
                $add('route_not_mail', $route['message'], $route['gateway']);

                continue;
            }

            $anyMail = true;

            // Decision E6: the bounce mailbox is read by the bundle only. A person who replies
            // there, or a sender that is the bounce mailbox, mixes the two up again.
            if (null !== $bounceAddress && self::same($route['from'], $bounceAddress)) {
                $add('route_from_is_bounce', $route['from']);
            }

            if (null !== $bounceAddress && self::same($route['replyTo'], $bounceAddress)) {
                $add('route_replyto_is_bounce', $route['replyTo']);
            }
        }

        if ($anyMail && !$mailboxConfigured) {
            $add('route_no_mailbox');
        } elseif ($anyMail && $mailboxFailing) {
            $add('route_mailbox_failing');
        }

        return array_values($warnings);
    }

    /**
     * The warnings of every choice the edit mask offers, each with the choices that cause it.
     * The browser shows a warning while at least one of its choices is selected; for any
     * selection that is the same set of warnings {@see warnings()} reports for the selected
     * routes together (its route warnings are per route, its mailbox warnings need any mail
     * route). The mailbox warnings stay last, as there. Pure.
     *
     * @param array<string, list<array{message: string, gateway: string, mail: bool, transport: string, from: string, fromSource: string, replyTo: string, answersTo: string, bounceTo: string, bounceToMailbox: bool}>> $routesByChoice choice (e.g. "invite:12") => its routes
     *
     * @return list<array{key: string, args: list<string>, choices: list<string>}>
     */
    public static function warningsByChoice(array $routesByChoice, ?string $bounceAddress, bool $mailboxConfigured, bool $mailboxFailing): array
    {
        $routeWarnings = [];
        $mailboxWarnings = [];

        foreach ($routesByChoice as $choice => $routes) {
            foreach (self::warnings($routes, $bounceAddress, $mailboxConfigured, $mailboxFailing) as $warning) {
                $id = $warning['key'].'|'.implode('|', $warning['args']);

                if (\in_array($warning['key'], ['route_no_mailbox', 'route_mailbox_failing'], true)) {
                    $mailboxWarnings[$id] ??= [...$warning, 'choices' => []];
                    $mailboxWarnings[$id]['choices'][] = (string) $choice;
                } else {
                    $routeWarnings[$id] ??= [...$warning, 'choices' => []];
                    $routeWarnings[$id]['choices'][] = (string) $choice;
                }
            }
        }

        return [...array_values($routeWarnings), ...array_values($mailboxWarnings)];
    }

    private static function same(string $a, string $b): bool
    {
        return '' !== $a && !str_contains($a, '##') && strtolower(trim($a)) === strtolower(trim($b));
    }

    /**
     * The bare address of a "Name <address>" value.
     */
    private static function addressOf(string $value): string
    {
        try {
            return Address::create($value)->getAddress();
        } catch (\Throwable) {
            return trim($value);
        }
    }
}
