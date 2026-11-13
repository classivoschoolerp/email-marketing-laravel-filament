<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

/**
 * Inbox rendering preview provider (spec §2.3.5 F-2.3.5-006, WP-46).
 *
 * The D-12 shape, verbatim: an interface plus one adapter per vendor, resolved
 * through {@see InboxPreviewRegistry}, so a new rendering farm is a new class
 * and never a core change.
 *
 * WHAT A PROVIDER CAN AND CANNOT DO HERE. Rendering an email in 50+ real
 * clients requires a rendering farm — actual copies of Outlook 2016, iOS Mail,
 * Gmail's web renderer — which cannot exist inside this application. Every
 * adapter is therefore a client of a paid third-party API and needs a real
 * account: Litmus (an Instant Previews plan) or Email on Acid (an API-enabled
 * plan). The adapters are implemented against those vendors' published HTTP
 * APIs and locked by Http::fake tests; nothing in this repository has ever
 * spoken to the live services, and {@see NullProvider} is what an install
 * without credentials actually gets. This is the same honest position D-12
 * takes for the email-verification adapters and D-76 for Localmail/Verifyre.
 */
interface InboxPreviewProvider
{
    /** Stable key used in `inbox_preview_runs.provider` and the UI. */
    public function key(): string;

    /** Human name, from the module lang file. */
    public function label(): string;

    /** True when this install has credentials for the vendor. */
    public function configured(): bool;

    /**
     * The client keys this provider will render, as the VENDOR names them.
     * May cost an HTTP call; callers cache it.
     *
     * @return array<string, string> vendor key => vendor label
     */
    public function clients(): array;

    /**
     * Submit one email for rendering and return the vendor's test id.
     *
     * @param  list<string>  $clients  vendor keys; [] means the vendor default set
     *
     * @throws InboxPreviewException when the vendor refuses the submission
     */
    public function submit(string $subject, string $html, ?string $text, array $clients = []): string;

    /**
     * Poll one submitted test. Never throws for "still rendering" — that is
     * an incomplete result, not an error.
     */
    public function results(string $remoteId): InboxPreviewResult;
}
