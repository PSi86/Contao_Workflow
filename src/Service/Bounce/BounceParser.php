<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

/**
 * Parses a delivery status notification (RFC 3464 "multipart/report") into per-recipient
 * {@see BounceReport}s. Self-contained on purpose: symfony/mime can build messages but not
 * parse raw ones, and pulling in a MIME-parsing library is unnecessary for the small, well
 * defined structure of a DSN. The parser therefore does its own minimal MIME splitting and
 * is completely free of I/O, so it is unit-testable against raw .eml fixtures.
 *
 * Correlation to the originating workflow mail is the Notification-Center-Parcel-ID header,
 * which the Notification Center sets on every outgoing mail and which is echoed back inside
 * the bounce's embedded original (message/rfc822 or, when the MTA truncated it,
 * text/rfc822-headers).
 *
 * Anything that is not a delivery-status report yields an empty array — the parser never
 * guesses a bounce from an ordinary reply or auto-responder.
 */
class BounceParser
{
    private const PARCEL_ID_HEADER = 'notification-center-parcel-id';

    /**
     * @return array<int, BounceReport> one entry per failed/reported recipient; empty if the
     *                                   message is not a delivery-status report
     */
    public function parse(string $raw): array
    {
        [$headerText, $body] = $this->splitHeadersAndBody($raw);
        $headers = $this->parseHeaders($headerText);

        $contentType = $headers['content-type'] ?? '';

        // Primary signal: a real DSN is a multipart/report with report-type=delivery-status.
        // Everything else (ordinary reply, vacation auto-responder, ...) is not a bounce.
        if (!$this->isDeliveryStatusReport($contentType)) {
            return [];
        }

        $boundary = $this->boundary($contentType);

        if (null === $boundary) {
            return [];
        }

        $parts = $this->splitParts($body, $boundary);

        $deliveryStatus = null;
        $parcelId = null;

        foreach ($parts as $part) {
            [$partHeaderText, $partBody] = $this->splitHeadersAndBody($part);
            $partType = $this->parseHeaders($partHeaderText)['content-type'] ?? '';

            if ($this->typeIs($partType, 'message/delivery-status')) {
                $deliveryStatus = $partBody;
            } elseif ($this->typeIs($partType, 'message/rfc822')) {
                // The embedded original: its own headers carry the parcel id.
                $parcelId ??= $this->parcelIdFrom($this->splitHeadersAndBody($partBody)[0]);
            } elseif ($this->typeIs($partType, 'text/rfc822-headers')) {
                // Only the headers of the original survived (MTA size limit): use them as-is,
                // but do not let them win over a full message/rfc822 part if both are present.
                $parcelId ??= $this->parcelIdFrom($partBody);
            }
        }

        if (null === $deliveryStatus) {
            return [];
        }

        return $this->parseRecipients($deliveryStatus, $parcelId);
    }

    /**
     * The message/delivery-status body is a sequence of header blocks separated by blank
     * lines: the first is per-message, each following one that carries a Final-Recipient is
     * a per-recipient report. A bounce may list several recipients.
     *
     * @return array<int, BounceReport>
     */
    private function parseRecipients(string $deliveryStatus, ?string $parcelId): array
    {
        $reports = [];

        foreach ($this->blocks($deliveryStatus) as $block) {
            $fields = $this->parseHeaders($block);

            if (!isset($fields['final-recipient'])) {
                continue;
            }

            $status = $this->fieldValue($fields['status'] ?? '');

            $reports[] = new BounceReport(
                $parcelId,
                $this->stripAddressType($fields['final-recipient']),
                strtolower($this->fieldValue($fields['action'] ?? '')),
                $this->statusClass($status),
                $status,
                trim($fields['diagnostic-code'] ?? ''),
            );
        }

        return $reports;
    }

    private function isDeliveryStatusReport(string $contentType): bool
    {
        return $this->typeIs($contentType, 'multipart/report')
            && preg_match('/report-type\s*=\s*"?delivery-status"?/i', $contentType) === 1;
    }

    /**
     * @return array{0: string, 1: string} header text, body
     */
    private function splitHeadersAndBody(string $raw): array
    {
        // Tolerate both CRLF and LF; the header/body separator is the first empty line.
        $offset = strpos($raw, "\r\n\r\n");
        $sep = 4;

        if (false === $offset) {
            $offset = strpos($raw, "\n\n");
            $sep = 2;
        }

        if (false === $offset) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $offset), substr($raw, $offset + $sep)];
    }

    /**
     * Parses header lines into a lower-cased name => value map, unfolding continuation lines
     * (RFC 5322 folding: a line starting with whitespace continues the previous header).
     * First occurrence wins.
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $headerText): array
    {
        $headers = [];
        $name = null;

        foreach (preg_split('/\r?\n/', $headerText) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }

            if (('' !== $line) && (' ' === $line[0] || "\t" === $line[0])) {
                // Continuation of the current header.
                if (null !== $name) {
                    $headers[$name] .= ' '.trim($line);
                }

                continue;
            }

            $pos = strpos($line, ':');

            if (false === $pos) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));

            // First occurrence wins.
            if (!isset($headers[$name])) {
                $headers[$name] = $value;
            } else {
                $name = null;
            }
        }

        return $headers;
    }

    /**
     * Splits a delivery-status body into its blank-line separated header blocks.
     *
     * @return array<int, string>
     */
    private function blocks(string $body): array
    {
        $blocks = preg_split('/\r?\n\r?\n/', trim($body)) ?: [];

        return array_values(array_filter(array_map('trim', $blocks), static fn (string $b): bool => '' !== $b));
    }

    /**
     * @return array<int, string>
     */
    private function splitParts(string $body, string $boundary): array
    {
        $segments = explode('--'.$boundary, $body);
        array_shift($segments); // preamble

        $parts = [];

        foreach ($segments as $segment) {
            // The closing delimiter is "--boundary--": its segment starts with "--".
            if (str_starts_with(ltrim($segment, "\r\n"), '--')) {
                break;
            }

            $parts[] = preg_replace('/^\r?\n/', '', $segment) ?? $segment;
        }

        return $parts;
    }

    private function boundary(string $contentType): ?string
    {
        if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/boundary\s*=\s*([^;\s]+)/i', $contentType, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function parcelIdFrom(string $headerText): ?string
    {
        $value = $this->parseHeaders($headerText)[self::PARCEL_ID_HEADER] ?? null;

        return null !== $value && '' !== $value ? $value : null;
    }

    private function typeIs(string $contentType, string $type): bool
    {
        return str_starts_with(strtolower(trim($contentType)), $type);
    }

    /**
     * The first digit of an SMTP status ("5.1.1" → 5). Only the class is trustworthy: an MTA
     * may report a generic "5.0.0" for a specific remote "5.1.1".
     */
    private function statusClass(string $status): int
    {
        return preg_match('/^\s*([245])\b/', $status, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * DSN fields can carry a comment/type prefix ("Final-Recipient: rfc822; a@b"); this
     * returns the part before the first ";".
     */
    private function fieldValue(string $value): string
    {
        return trim($value);
    }

    private function stripAddressType(string $finalRecipient): string
    {
        $pos = strpos($finalRecipient, ';');

        return $pos === false ? trim($finalRecipient) : trim(substr($finalRecipient, $pos + 1));
    }
}
