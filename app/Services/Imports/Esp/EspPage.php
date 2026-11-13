<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp;

/**
 * One page of contacts fetched from an external ESP (spec §2.2.7
 * F-2.2.7-006). `cursor` is whatever the adapter needs to ask for the NEXT
 * page — a numeric offset for offset-paginated vendors (Mailchimp, Brevo),
 * an opaque href/URL for cursor-paginated ones (Constant Contact, Klaviyo) —
 * and null when the vendor reported the last page.
 */
final class EspPage
{
    /** @param list<array{email: string, fields: array<string, string>}> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly ?string $cursor = null,
    ) {}
}
