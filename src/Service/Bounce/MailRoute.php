<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Contao\CoreBundle\Mailer\AvailableTransports;
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
        if ($notificationId <= 0) {
            return [];
        }

        try {
            $messages = $this->connection->fetchAllAssociative(
                "SELECT m.id, m.title, g.title AS gatewayTitle, g.type, g.mailerTransport
                   FROM tl_nc_message m
                   LEFT JOIN tl_nc_gateway g ON g.id = m.gateway
                  WHERE m.pid = ? AND m.published = '1'
                  ORDER BY m.sorting, m.id",
                [$notificationId],
            );
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

            if (!$route['mail']) {
                $routes[] = $route;

                continue;
            }

            $language = $this->connection->fetchAssociative(
                'SELECT email_sender_address, email_replyTo FROM tl_nc_language WHERE pid = ? ORDER BY fallback DESC, id LIMIT 1',
                [(int) $message['id']],
            ) ?: [];

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

            $routes[] = $route;
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
