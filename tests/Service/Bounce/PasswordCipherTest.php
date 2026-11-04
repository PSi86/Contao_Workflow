<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\PasswordCipher;

final class PasswordCipherTest extends TestCase
{
    protected function setUp(): void
    {
        if (!PasswordCipher::isAvailable()) {
            self::markTestSkipped('ext-sodium is not available.');
        }
    }

    public function testRoundTrip(): void
    {
        $cipher = new PasswordCipher('app-secret-1');
        $stored = $cipher->encrypt('pa+ss wörd%40');

        self::assertStringNotContainsString('pa+ss', $stored);
        self::assertSame('pa+ss wörd%40', $cipher->decrypt($stored));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $cipher = new PasswordCipher('app-secret-1');

        self::assertNotSame($cipher->encrypt('same'), $cipher->encrypt('same'));
    }

    public function testAnotherAppSecretCannotDecrypt(): void
    {
        $stored = (new PasswordCipher('app-secret-1'))->encrypt('secret');

        self::assertNull((new PasswordCipher('app-secret-2'))->decrypt($stored));
    }

    public function testDamagedValuesCannotBeDecrypted(): void
    {
        $cipher = new PasswordCipher('app-secret-1');
        $stored = $cipher->encrypt('secret');
        $raw = (string) base64_decode($stored, true);
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] ^ "\x01";

        self::assertNull($cipher->decrypt(base64_encode($raw)));
        self::assertNull($cipher->decrypt('not base64 !'));
        self::assertNull($cipher->decrypt(base64_encode('short')));
    }
}
