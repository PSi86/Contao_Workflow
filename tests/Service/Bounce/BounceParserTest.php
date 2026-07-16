<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceParser;
use Psimandl\WorkflowBundle\Service\Bounce\BounceReport;

final class BounceParserTest extends TestCase
{
    private BounceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BounceParser();
    }

    public function testParsesAHardBounceFixture(): void
    {
        $reports = $this->parser->parse($this->fixture('bounce-hard-550.eml'));

        self::assertCount(1, $reports);
        $report = $reports[0];

        self::assertSame('ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00', $report->parcelId);
        self::assertSame('sdfdfsdfsd@wherever-we-are.com', $report->recipient);
        self::assertSame('failed', $report->action);
        self::assertSame(5, $report->statusClass, 'only the first status digit is trusted (Postfix wrote 5.0.0 for a 5.1.1)');
        self::assertSame('5.0.0', $report->status);
        self::assertStringContainsString('User unknown', $report->diagnosticCode);
        self::assertTrue($report->isHardBounce());
        self::assertFalse($report->isSoftBounce());
    }

    public function testAnOrdinaryMailIsNotTreatedAsABounce(): void
    {
        self::assertSame([], $this->parser->parse($this->fixture('delivered-reminder.eml')));
    }

    public function testCrlfLineEndingsAreHandled(): void
    {
        $crlf = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $this->fixture('bounce-hard-550.eml')));

        $reports = $this->parser->parse($crlf);

        self::assertCount(1, $reports);
        self::assertSame('failed', $reports[0]->action);
        self::assertSame(5, $reports[0]->statusClass);
        self::assertSame('ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00', $reports[0]->parcelId);
    }

    public function testDelayedIsASoftBounce(): void
    {
        $dsn = $this->dsn(
            "Final-Recipient: rfc822; late@example.com\nAction: delayed\nStatus: 4.4.1\n"
            ."Diagnostic-Code: smtp; 451 4.4.1 connection timed out",
        );

        $reports = $this->parser->parse($dsn);

        self::assertCount(1, $reports);
        self::assertSame('delayed', $reports[0]->action);
        self::assertSame(4, $reports[0]->statusClass);
        self::assertTrue($reports[0]->isSoftBounce());
        self::assertFalse($reports[0]->isHardBounce());
    }

    public function testParsesMultipleRecipientBlocks(): void
    {
        $dsn = $this->dsn(
            "Final-Recipient: rfc822; dead@example.com\nAction: failed\nStatus: 5.1.1\n"
            ."Diagnostic-Code: smtp; 550 User unknown\n"
            ."\n"
            ."Final-Recipient: rfc822; slow@example.com\nAction: delayed\nStatus: 4.2.2\n"
            ."Diagnostic-Code: smtp; 452 Mailbox full",
        );

        $reports = $this->parser->parse($dsn);

        self::assertCount(2, $reports);
        self::assertSame('dead@example.com', $reports[0]->recipient);
        self::assertTrue($reports[0]->isHardBounce());
        self::assertSame('slow@example.com', $reports[1]->recipient);
        self::assertTrue($reports[1]->isSoftBounce());
    }

    public function testHardBounceWithoutEmbeddedOriginalHasNoParcelId(): void
    {
        $dsn = $this->dsn(
            "Final-Recipient: rfc822; dead@example.com\nAction: failed\nStatus: 5.1.1\n"
            ."Diagnostic-Code: smtp; 550 User unknown",
            embeddedType: null,
        );

        $reports = $this->parser->parse($dsn);

        self::assertCount(1, $reports);
        self::assertNull($reports[0]->parcelId, 'no message/rfc822 part means the parcel id is unknown');
        self::assertTrue($reports[0]->isHardBounce());
    }

    public function testParcelIdFallsBackToRfc822HeadersPart(): void
    {
        $dsn = $this->dsn(
            "Final-Recipient: rfc822; dead@example.com\nAction: failed\nStatus: 5.1.1\n"
            ."Diagnostic-Code: smtp; 550 User unknown",
            embeddedType: 'text/rfc822-headers',
        );

        $reports = $this->parser->parse($dsn);

        self::assertCount(1, $reports);
        self::assertSame('PARCEL0000000000', $reports[0]->parcelId, 'the parcel id survives even when only the headers of the original are returned');
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$name);
    }

    /**
     * Builds a minimal but structurally faithful multipart/report DSN around the given
     * per-recipient block(s). $embeddedType selects how the original is echoed back:
     * message/rfc822 (default), text/rfc822-headers, or null for no embedded original.
     */
    private function dsn(string $recipientBlocks, ?string $embeddedType = 'message/rfc822'): string
    {
        $b = 'BOUNDARY42xyz';

        $embedded = '';

        if ('text/rfc822-headers' === $embeddedType) {
            $embedded = "--$b\n"
                ."Content-Type: text/rfc822-headers\n\n"
                ."Notification-Center-Parcel-ID: PARCEL0000000000\n"
                ."From: noreply@tsvkorntal.com\n"
                ."To: x@example.com\n";
        } elseif (null !== $embeddedType) {
            $embedded = "--$b\n"
                ."Content-Type: message/rfc822\n\n"
                ."Notification-Center-Parcel-ID: PARCEL0000000000\n"
                ."From: noreply@tsvkorntal.com\n"
                ."To: x@example.com\n"
                ."Subject: Erinnerung\n\n"
                ."Original message body.\n";
        }

        return "From: Mail Delivery System <MAILER-DAEMON@host>\n"
            ."To: noreply@tsvkorntal.com\n"
            ."Content-Type: multipart/report; report-type=delivery-status; boundary=\"$b\"\n\n"
            ."--$b\n"
            ."Content-Type: text/plain\n\n"
            ."Delivery to the following recipient failed.\n\n"
            ."--$b\n"
            ."Content-Type: message/delivery-status\n\n"
            ."Reporting-MTA: dns; host\n\n"
            .$recipientBlocks."\n\n"
            .$embedded
            ."--$b--\n";
    }
}
