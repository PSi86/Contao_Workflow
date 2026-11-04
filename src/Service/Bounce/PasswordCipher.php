<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

/**
 * Encrypts the bounce mailbox password for storage in the database (libsodium secretbox).
 *
 * The key is derived from the application secret (APP_SECRET / kernel.secret), which lives in
 * the files, not in the database: a database dump alone does not reveal the password. Changing
 * APP_SECRET makes stored passwords unreadable – decrypt() then returns null and the back end
 * asks for the password again.
 */
class PasswordCipher
{
    private const KEY_CONTEXT = 'psimandl_workflow_bounce_mailbox_password';

    public function __construct(private readonly string $appSecret)
    {
    }

    public static function isAvailable(): bool
    {
        return \function_exists('sodium_crypto_secretbox');
    }

    /**
     * @return string base64 of nonce and ciphertext
     */
    public function encrypt(string $plain): string
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('The PHP extension sodium is not available.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /**
     * Null when the value cannot be decrypted: other key (APP_SECRET changed), damaged value,
     * or no sodium.
     */
    public function decrypt(string $stored): ?string
    {
        if (!self::isAvailable()) {
            return null;
        }

        $raw = base64_decode($stored, true);

        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );

        return false === $plain ? null : $plain;
    }

    private function key(): string
    {
        return sodium_crypto_generichash(self::KEY_CONTEXT.'|'.$this->appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
