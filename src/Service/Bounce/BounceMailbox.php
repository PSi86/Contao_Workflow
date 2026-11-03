<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

/**
 * The one place that knows the bounce mailbox: its IMAP access (read by the bounce collector)
 * and its address, which every workflow mail carries as envelope sender (SMTP MAIL FROM) so
 * that bounces land there.
 *
 * Two sources, in this order:
 *
 *  1. WORKFLOW_BOUNCE_IMAP_DSN in .env.local. When set, it wins; the back-end settings are
 *     then only shown, never used.
 *  2. The back-end settings ({@see BounceSettings}), set up in the workflow overview.
 *
 * The address is WORKFLOW_BOUNCE_ADDRESS when set (an explicit value that is not a valid
 * address yields no address rather than a silent fallback); otherwise the address of the
 * source – the back-end field, or the DSN user when it is an e-mail address. Without an
 * address the envelope sender is left to the mailer, which derives it from From.
 */
class BounceMailbox
{
    public const SOURCE_ENV = 'env';

    public const SOURCE_BACKEND = 'backend';

    public function __construct(
        private readonly ?string $bounceImapDsn = null,
        private readonly ?string $bounceAddress = null,
        private readonly ?BounceSettings $settings = null,
    ) {
    }

    /**
     * Where the mailbox comes from: SOURCE_ENV, SOURCE_BACKEND, or null when none is set up.
     */
    public function source(): ?string
    {
        if ('' !== $this->envDsn()) {
            return self::SOURCE_ENV;
        }

        return null !== $this->backend() ? self::SOURCE_BACKEND : null;
    }

    /**
     * The IMAP DSN the collector uses; empty when there is none – also when the back-end
     * password cannot be decrypted ({@see passwordUnreadable()}).
     */
    public function imapDsn(): string
    {
        $env = $this->envDsn();

        if ('' !== $env) {
            return $env;
        }

        $settings = $this->backend();

        if (null === $settings || null === $settings['password']) {
            return '';
        }

        return self::buildDsn($settings['host'], $settings['port'], $settings['encryption'], $settings['user'], $settings['password']);
    }

    public function address(): ?string
    {
        $explicit = trim((string) $this->bounceAddress);

        if ('' !== $explicit) {
            return self::isAddress($explicit) ? $explicit : null;
        }

        $env = $this->envDsn();

        if ('' !== $env) {
            return self::userAddress($env);
        }

        $settings = $this->backend();

        if (null === $settings) {
            return null;
        }

        $candidate = '' !== $settings['address'] ? $settings['address'] : $settings['user'];

        return self::isAddress($candidate) ? $candidate : null;
    }

    /**
     * Back-end settings whose stored password cannot be decrypted any more (APP_SECRET changed):
     * the mailbox cannot be read until the password is entered again.
     */
    public function passwordUnreadable(): bool
    {
        if ('' !== $this->envDsn()) {
            return false;
        }

        $settings = $this->backend();

        return null !== $settings && $settings['passwordSet'] && null === $settings['password'];
    }

    /**
     * The back-end settings, also when WORKFLOW_BOUNCE_IMAP_DSN overrides them.
     *
     * @return array{address: string, host: string, port: int, encryption: string, user: string, passwordSet: bool, password: ?string}|null
     */
    public function backend(): ?array
    {
        return $this->settings?->read();
    }

    /**
     * Server, port and user of WORKFLOW_BOUNCE_IMAP_DSN, for display (no password).
     *
     * @return array{host: string, port: int, user: string}|null
     */
    public function envSummary(): ?array
    {
        $env = $this->envDsn();

        if ('' === $env) {
            return null;
        }

        $parts = parse_url($env);

        return [
            'host' => (string) ($parts['host'] ?? ''),
            'port' => (int) ($parts['port'] ?? 993),
            'user' => rawurldecode((string) ($parts['user'] ?? '')),
        ];
    }

    public static function buildDsn(string $host, int $port, string $encryption, string $user, string $password): string
    {
        $query = match ($encryption) {
            'tls' => 'tls=true',
            'none' => 'ssl=false',
            default => 'ssl=true',
        };

        return \sprintf('imap://%s:%s@%s:%d?%s', rawurlencode($user), rawurlencode($password), $host, $port, $query);
    }

    private function envDsn(): string
    {
        return trim((string) $this->bounceImapDsn);
    }

    private static function userAddress(string $dsn): ?string
    {
        $user = parse_url($dsn, PHP_URL_USER);

        if (!\is_string($user) || '' === $user) {
            return null;
        }

        // rawurldecode, not urldecode: a "+" in the user part is a literal plus.
        $user = rawurldecode($user);

        return self::isAddress($user) ? $user : null;
    }

    private static function isAddress(string $value): bool
    {
        return false !== filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
