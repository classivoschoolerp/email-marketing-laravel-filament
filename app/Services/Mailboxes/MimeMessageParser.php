<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

/**
 * Lightweight recursive MIME parser shared by the DSN and ARF parsers
 * (spec §2.9.4/§2.9.5). Handles what real bounce traffic needs: header
 * unfolding, multipart boundaries (nested), embedded message/rfc822 and
 * text/rfc822-headers parts, and quoted-printable / base64 text bodies.
 */
class MimeMessageParser
{
    public function parse(string $raw): MimePart
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        [$headerBlock, $body] = $this->splitHeadersFromBody($raw);
        $headers = $this->parseHeaders($headerBlock);

        $part = new MimePart($headers, $this->decodeBody($headers, $body));

        return $this->expand($part);
    }

    /** @return array{0: string, 1: string} */
    private function splitHeadersFromBody(string $raw): array
    {
        $separator = strpos($raw, "\n\n");

        if ($separator === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $separator), substr($raw, $separator + 2)];
    }

    /** @return array<string, string> */
    private function parseHeaders(string $block): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }

            // Folded continuation line (RFC 5322 §2.2.3).
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' '.trim($line);

                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name === '') {
                continue;
            }

            $current = $name;
            $headers[$name] ??= $value; // keep the first occurrence
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private function decodeBody(array $headers, string $body): string
    {
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));

        return match ($encoding) {
            'quoted-printable' => quoted_printable_decode($body),
            'base64' => (string) base64_decode($body, false),
            default => $body,
        };
    }

    /** Recursively expand multipart bodies and embedded rfc822 messages. */
    private function expand(MimePart $part): MimePart
    {
        $type = $part->contentType();

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $part->contentTypeParameter('boundary');

            if ($boundary === null || $boundary === '') {
                return $part;
            }

            $children = [];

            foreach ($this->splitOnBoundary($part->body, $boundary) as $chunk) {
                [$headerBlock, $body] = $this->splitHeadersFromBody($chunk);
                $headers = $this->parseHeaders($headerBlock);
                $children[] = $this->expand(new MimePart($headers, $this->decodeBody($headers, $body)));
            }

            return new MimePart($part->headers, $part->body, $children);
        }

        if ($type === 'message/rfc822') {
            $embedded = $this->parse($part->body);

            return new MimePart($part->headers, $part->body, [$embedded]);
        }

        if ($type === 'text/rfc822-headers') {
            // Header-only copy of the original message: expose it like an
            // embedded message so callers read headers uniformly.
            $embedded = new MimePart($this->parseHeaders(trim($part->body)), '');

            return new MimePart($part->headers, $part->body, [$embedded]);
        }

        return $part;
    }

    /** @return list<string> */
    private function splitOnBoundary(string $body, string $boundary): array
    {
        $chunks = [];
        $current = null;
        $delimiter = '--'.$boundary;

        foreach (explode("\n", $body) as $line) {
            $trimmed = rtrim($line);

            if ($trimmed === $delimiter || $trimmed === $delimiter.'--') {
                if ($current !== null && trim($current) !== '') {
                    $chunks[] = ltrim($current, "\n");
                }

                $current = $trimmed === $delimiter ? '' : null;

                continue;
            }

            if ($current !== null) {
                $current .= $line."\n";
            }
        }

        if ($current !== null && trim($current) !== '') {
            $chunks[] = ltrim($current, "\n");
        }

        return $chunks;
    }
}
