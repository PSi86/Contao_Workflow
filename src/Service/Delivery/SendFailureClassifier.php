<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Delivery;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Tells a permanent rejection of the RECIPIENT apart from every other send failure.
 *
 * Only the former may block an address (like a hard bounce does): the mail server has said
 * that this address does not exist, so sending to it again is pointless and hurts the sender's
 * reputation. Everything else – server unreachable, login failed, too many connections, any
 * temporary 4xx, a rejected sender – affects every recipient alike and must stay retryable;
 * blocking on it would lock out the whole participant list after one SMTP outage.
 *
 * Two conditions have to hold, both read from the exception Symfony's SmtpTransport throws:
 *
 *  - The failing command was RCPT TO. Symfony expects "250/251/252" after RCPT TO and after no
 *    other command, and says so in the message ("Expected response code "250/251/252" but
 *    got …"). That alone is not enough: Postfix evaluates its sender restrictions at RCPT time
 *    too, so a rejected sender ("553 5.7.1 Sender address rejected") arrives at the same spot.
 *  - The reply carries a permanent address status from RFC 3463 that concerns the recipient:
 *    5.1.1 bad mailbox, 5.1.2 bad domain, 5.1.3 bad address syntax, 5.1.4 ambiguous,
 *    5.1.6 mailbox moved, 5.1.10 null MX (RFC 7505). 5.1.7/5.1.8 concern the sender and are
 *    left out on purpose. A reply without an enhanced status code is not trusted.
 *
 * An address the mailer cannot even parse never gets this far: NotificationDispatcher checks
 * it before handing it over (an invalid address throws for the template's sender as well, so
 * the exception alone could not say whose address was wrong).
 */
class SendFailureClassifier
{
    private const RCPT_EXPECTATION = 'Expected response code "250/251/252"';

    private const RECIPIENT_STATUS = '/(?<![\d.])5\.1\.(?:1|2|3|4|6|10)(?![\d.])/';

    /**
     * The server's reply when the failure is a permanent rejection of the recipient, null
     * otherwise. Walks the exception chain, since the transport error may arrive wrapped.
     */
    public function permanentAddressRejection(?\Throwable $error): ?string
    {
        for ($e = $error; null !== $e; $e = $e->getPrevious()) {
            if (!$e instanceof TransportExceptionInterface) {
                continue;
            }

            $message = $e->getMessage();

            if (!str_contains($message, self::RCPT_EXPECTATION)) {
                continue;
            }

            if (!preg_match('/but got code "(\d{3})"/', $message, $code) || '5' !== $code[1][0]) {
                continue;
            }

            if (!preg_match(self::RECIPIENT_STATUS, $message)) {
                continue;
            }

            return $this->serverReply($message);
        }

        return null;
    }

    private function serverReply(string $message): string
    {
        if (preg_match('/with message "(.*)"\.?$/s', $message, $match)) {
            return trim($match[1]);
        }

        return trim($message);
    }
}
