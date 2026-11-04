<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceMailbox;

final class BounceMailboxTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function addresses(): iterable
    {
        yield 'nothing configured' => [null, null, null];
        yield 'empty strings' => ['', '  ', null];
        yield 'DSN user is an address' => ['imap://bounces%40example.org:secret@mail.example.org:993?ssl=true', null, 'bounces@example.org'];
        yield 'plus in the DSN user stays a plus' => ['imap://bounces%2Bwf%40example.org:secret@mail.example.org:993', null, 'bounces+wf@example.org'];
        yield 'DSN user is a mailbox name' => ['imap://m0123456:secret@w0123456.kasserver.com:993', null, null];
        yield 'explicit address wins over the DSN user' => ['imap://bounces%40example.org:secret@mail.example.org:993', 'workflow@example.org', 'workflow@example.org'];
        yield 'explicit address with a mailbox-name login' => ['imap://m0123456:secret@w0123456.kasserver.com:993', 'workflow@example.org', 'workflow@example.org'];
        yield 'explicit address without DSN' => [null, ' workflow@example.org ', 'workflow@example.org'];
        yield 'invalid explicit address does not fall back' => ['imap://bounces%40example.org:secret@mail.example.org:993', 'not-an-address', null];
    }

    #[DataProvider('addresses')]
    public function testAddress(?string $dsn, ?string $explicit, ?string $expected): void
    {
        self::assertSame($expected, (new BounceMailbox($dsn, $explicit))->address());
    }

    public function testImapDsnIsTrimmed(): void
    {
        self::assertSame('imap://u:p@h:993', (new BounceMailbox(' imap://u:p@h:993 '))->imapDsn());
        self::assertSame('', (new BounceMailbox())->imapDsn());
    }
}
