<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

/**
 * A parsed MIME entity: unfolded headers, decoded-enough body, child parts
 * (multipart/* or an embedded message/rfc822). Produced by MimeMessageParser
 * — just enough structure for DSN (RFC 3464) and ARF (RFC 5965) processing;
 * not a general-purpose MIME library.
 */
final class MimePart
{
    /**
     * @param  array<string, string>  $headers  lower-cased name => unfolded value (first occurrence)
     * @param  list<MimePart>  $parts
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $parts = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Lower-cased mime type without parameters, e.g. "message/delivery-status". */
    public function contentType(): string
    {
        $raw = $this->header('content-type') ?? 'text/plain';

        return strtolower(trim(explode(';', $raw)[0]));
    }

    /** A Content-Type parameter (boundary, report-type, ...), unquoted. */
    public function contentTypeParameter(string $parameter): ?string
    {
        $raw = $this->header('content-type');

        if ($raw === null) {
            return null;
        }

        if (preg_match('/'.preg_quote($parameter, '/').'\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $raw, $m) === 1) {
            return $m[1] !== '' ? $m[1] : ($m[2] ?? null);
        }

        return null;
    }

    /** Depth-first search for the first part with the given mime type. */
    public function findPart(string $contentType): ?self
    {
        if ($this->contentType() === strtolower($contentType)) {
            return $this;
        }

        foreach ($this->parts as $part) {
            $found = $part->findPart($contentType);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
