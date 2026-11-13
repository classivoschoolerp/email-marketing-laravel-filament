<?php

declare(strict_types=1);

namespace App\Services\Templates\Stock;

/**
 * Stock image library adapter (spec §2.5.4 F-2.5.4-010).
 *
 * Driver registry from day one (docs/ARCHITECTURE.md rule 2): a new library implements
 * this interface and is listed in config('emp-templates.stock.providers').
 * Neither the picker UI nor the import endpoint changes.
 */
interface StockImageProvider
{
    /** Stable key used in config, requests and the credential store. */
    public function key(): string;

    /** Human label for the picker's library selector. */
    public function label(): string;

    /** False when no API key is available — the library is then never offered. */
    public function isConfigured(): bool;

    /**
     * Search the library.
     *
     * @return list<StockPhoto> empty on any transport or shape failure — a
     *                          stock library must never break the builder
     */
    public function search(string $query, int $page = 1, int $perPage = 24): array;

    /**
     * Hosts the provider serves its image binaries from.
     *
     * The import endpoint downloads the URL the BROWSER hands back, so this
     * allowlist is the SSRF fence: anything not served by the library itself
     * is refused outright rather than fetched and inspected.
     *
     * @return list<string>
     */
    public function imageHosts(): array;
}
