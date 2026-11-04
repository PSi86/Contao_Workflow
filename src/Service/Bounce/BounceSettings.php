<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Doctrine\DBAL\Connection;

/**
 * The bounce mailbox as set up in the back end: one row in tl_workflow_settings, the password
 * encrypted with {@see PasswordCipher}. WORKFLOW_BOUNCE_IMAP_DSN in .env.local takes precedence
 * ({@see BounceMailbox}); these settings are then only shown.
 *
 * Every read is guarded: right after the bundle update the table may not exist yet, which
 * simply means "nothing set up in the back end".
 */
class BounceSettings
{
    public const ENCRYPTIONS = ['ssl', 'tls', 'none'];

    private const ROW_ID = 1;

    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordCipher $cipher,
    ) {
    }

    /**
     * Null when nothing is set up. "password" is null when none is stored or the stored one
     * cannot be decrypted ("passwordSet" tells the two apart).
     *
     * @return array{address: string, host: string, port: int, encryption: string, user: string, passwordSet: bool, password: ?string}|null
     */
    public function read(): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT bounceAddress, imapHost, imapPort, imapEncryption, imapUser, imapPasswordEnc FROM tl_workflow_settings WHERE id = ?',
                [self::ROW_ID],
            );
        } catch (\Throwable) {
            return null;
        }

        if (false === $row || '' === trim((string) $row['imapHost'])) {
            return null;
        }

        $stored = (string) ($row['imapPasswordEnc'] ?? '');
        $encryption = (string) $row['imapEncryption'];

        return [
            'address' => (string) $row['bounceAddress'],
            'host' => (string) $row['imapHost'],
            'port' => (int) $row['imapPort'] > 0 ? (int) $row['imapPort'] : 993,
            'encryption' => \in_array($encryption, self::ENCRYPTIONS, true) ? $encryption : 'ssl',
            'user' => (string) $row['imapUser'],
            'passwordSet' => '' !== $stored,
            'password' => '' !== $stored ? $this->cipher->decrypt($stored) : null,
        ];
    }

    /**
     * @param array{address: string, host: string, port: int, encryption: string, user: string} $values
     * @param string|null                                                                     $password null keeps the stored one
     */
    public function save(array $values, ?string $password): void
    {
        $data = [
            'tstamp' => time(),
            'bounceAddress' => $values['address'],
            'imapHost' => $values['host'],
            'imapPort' => $values['port'],
            'imapEncryption' => $values['encryption'],
            'imapUser' => $values['user'],
        ];

        if (null !== $password) {
            $data['imapPasswordEnc'] = $this->cipher->encrypt($password);
        }

        $exists = false !== $this->connection->fetchOne('SELECT id FROM tl_workflow_settings WHERE id = ?', [self::ROW_ID]);

        if ($exists) {
            $this->connection->update('tl_workflow_settings', $data, ['id' => self::ROW_ID]);
        } else {
            $this->connection->insert('tl_workflow_settings', ['id' => self::ROW_ID] + $data);
        }
    }

    public function clear(): void
    {
        $this->connection->delete('tl_workflow_settings', ['id' => self::ROW_ID]);
    }

    /**
     * Checks submitted values; returns the problems as language keys (empty: all fine).
     *
     * @param array{address: string, host: string, port: int, encryption: string, user: string} $values
     *
     * @return list<string>
     */
    public static function validate(array $values, bool $passwordGiven, bool $passwordStored): array
    {
        $problems = [];

        if (false === filter_var($values['address'], FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'mailbox_err_address';
        }

        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $values['host'])) {
            $problems[] = 'mailbox_err_host';
        }

        if ($values['port'] < 1 || $values['port'] > 65535) {
            $problems[] = 'mailbox_err_port';
        }

        if (!\in_array($values['encryption'], self::ENCRYPTIONS, true)) {
            $problems[] = 'mailbox_err_encryption';
        }

        if ('' === $values['user']) {
            $problems[] = 'mailbox_err_user';
        }

        if (!$passwordGiven && !$passwordStored) {
            $problems[] = 'mailbox_err_password';
        }

        if ($passwordGiven && !PasswordCipher::isAvailable()) {
            $problems[] = 'mailbox_err_sodium';
        }

        return $problems;
    }
}
