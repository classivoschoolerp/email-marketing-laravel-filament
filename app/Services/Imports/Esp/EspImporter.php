<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp;

/**
 * One pluggable "import from another ESP" adapter (spec §2.2.7
 * F-2.2.7-006). Implementations register in {@see EspImporterRegistry} —
 * Mailchimp, Brevo, Constant Contact and Klaviyo ship in core, and a plugin
 * adds a vendor without a core change (docs/ARCHITECTURE.md architecture rule #2, same
 * shape as App\Services\Verification\ProviderRegistry).
 *
 * An adapter ONLY knows how to page through the vendor's contact API and
 * normalise a page to email + string fields. It never writes subscribers:
 * {@see \App\Services\Imports\Readers\EspReader} materialises the pages as a
 * CSV on the private disk and hands them to the existing chunked import
 * pipeline, so duplicate handling, suppression, auto-created fields, the
 * progress bar and the error CSV all behave identically to a CSV upload.
 */
interface EspImporter
{
    /** Registry key, e.g. "mailchimp". */
    public function key(): string;

    /** Human name for the source picker, e.g. "Mailchimp". */
    public function name(): string;

    /**
     * Credential keys this adapter needs, in form order. Values are supplied
     * by the import form and stored encrypted in import_jobs.options.
     *
     * @return list<string>
     */
    public function credentialKeys(): array;

    /**
     * Fetch one page of contacts. $cursor is null for the first page and
     * otherwise the value returned by the previous page.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws \App\Services\Imports\ImportFailedException on bad credentials or a vendor error
     */
    public function fetch(array $credentials, ?string $cursor): EspPage;
}
