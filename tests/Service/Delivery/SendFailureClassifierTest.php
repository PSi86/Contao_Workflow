<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Delivery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Delivery\SendFailureClassifier;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Only a mail server that rejects the RECIPIENT for good may block an address. Everything
 * else – the server unreachable, a failed login, too many connections, a temporary 4xx, a
 * rejected SENDER – hits every recipient alike; blocking on it would lock out the whole list.
 *
 * The messages are the ones Symfony's SmtpTransport builds (assertResponseCode).
 */
final class SendFailureClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function permanentRejections(): iterable
    {
        yield 'unknown local user (Postfix)' => [
            self::rcpt(550, '550 5.1.1 <nobody@tsvkorntal.de>: Recipient address rejected: User unknown in virtual mailbox table'),
            '550 5.1.1 <nobody@tsvkorntal.de>: Recipient address rejected: User unknown in virtual mailbox table',
        ];

        yield 'multi-line reply (Gmail style)' => [
            self::rcpt(550, '550-5.1.1 The email account that you tried to reach does not exist.'),
            '550-5.1.1 The email account that you tried to reach does not exist.',
        ];

        yield 'domain publishes a null MX' => [
            self::rcpt(556, '556 5.1.10 Recipient address has null MX'),
            '556 5.1.10 Recipient address has null MX',
        ];

        yield 'wrapped by another exception' => [
            new \RuntimeException('Handling failed', 0, self::rcpt(550, '550 5.1.1 user unknown')),
            '550 5.1.1 user unknown',
        ];
    }

    #[DataProvider('permanentRejections')]
    public function testPermanentRecipientRejectionIsRecognised(\Throwable $error, string $reason): void
    {
        self::assertSame($reason, (new SendFailureClassifier())->permanentAddressRejection($error));
    }

    /**
     * @return iterable<string, array{\Throwable|null}>
     */
    public static function notAnAddressProblem(): iterable
    {
        // Postfix evaluates sender restrictions at RCPT time, so a rejected SENDER arrives
        // exactly where a rejected recipient would – with a 5.7.x code.
        yield 'sender rejected at RCPT' => [self::rcpt(553, '553 5.7.1 <noreply@tsvkorntal.de>: Sender address rejected: not owned by user w0123')];
        yield 'relaying denied' => [self::rcpt(554, '554 5.7.1 <x@example.org>: Relay access denied')];
        yield 'temporary: domain not found' => [self::rcpt(450, '450 4.1.2 <x@nowhere.invalid>: Recipient address rejected: Domain not found')];
        yield 'temporary: mailbox full' => [self::rcpt(452, '452 4.2.2 Mailbox full')];
        yield 'too many connections' => [new TransportException('Expected response code "220" but got code "421", with message "421 4.7.0 Too many concurrent SMTP connections".', 421)];
        yield 'login failed' => [new TransportException('Failed to authenticate on SMTP server with username "workflow@tsvkorntal.de" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".".')];
        yield 'server unreachable' => [new TransportException('Connection could not be established with host "ssl://w0123.kasserver.com:465": stream_socket_client(): Unable to connect (Connection timed out)')];
        yield 'MAIL FROM rejected' => [new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.1.8 <noreply@x>: Sender address rejected: Domain not found".', 550)];
        yield 'sender address mailbox syntax at RCPT' => [self::rcpt(553, '553 5.1.7 Bad sender address syntax')];
        yield 'no enhanced status code' => [self::rcpt(550, '550 User unknown')];
        yield 'invalid sender in the template' => [new \RuntimeException('Email "##admin_email##" does not comply with addr-spec of RFC 2822.')];
        yield 'no exception at all' => [null];
    }

    #[DataProvider('notAnAddressProblem')]
    public function testEverythingElseIsNotAPermanentAddressProblem(?\Throwable $error): void
    {
        self::assertNull((new SendFailureClassifier())->permanentAddressRejection($error));
    }

    private static function rcpt(int $code, string $reply): UnexpectedResponseException
    {
        return new UnexpectedResponseException(
            \sprintf('Expected response code "250/251/252" but got code "%d", with message "%s".', $code, $reply),
            $code,
        );
    }
}
