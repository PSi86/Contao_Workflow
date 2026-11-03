<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

/**
 * Knows the bounce mailbox: its address, which every workflow mail carries as envelope
 * sender (SMTP MAIL FROM) so that bounces land there, and its IMAP DSN.
 *
 * The address is, in this order:
 *
 *  1. WORKFLOW_BOUNCE_ADDRESS, when set. Needed when the IMAP login is not an address (an
 *     All-Inkl mailbox can also log in with its mailbox name, e.g. m0123456). An explicit
 *     value that is not a valid address yields no address rather than a silent fallback.
 *  2. The user of WORKFLOW_BOUNCE_IMAP_DSN, when it is an e-mail address.
 *
 * Without an address the envelope sender is left to the mailer, which derives it from the
 * From header – the behaviour before 3.6.0.
 */
class BounceMailbox
{
    public function __construct(
        private readonly ?string $bounceImapDsn = null,
        private readonly ?string $bounceAddress = null,
    ) {
    }

    public function imapDsn(): string
    {
        return trim((string) $this->bounceImapDsn);
    }

    public function address(): ?string
    {
        $explicit = trim((string) $this->bounceAddress);

        if ('' !== $explicit) {
            return self::isAddress($explicit) ? $explicit : null;
        }

        $user = parse_url($this->imapDsn(), PHP_URL_USER);

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
