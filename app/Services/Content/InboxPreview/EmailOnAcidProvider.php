<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

use App\Settings\InboxPreviewSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Email on Acid adapter (spec §2.3.5 F-2.3.5-006, WP-46).
 *
 * IMPLEMENTED AGAINST EMAIL ON ACID'S PUBLISHED v5 API, NOT AGAINST A LIVE
 * ACCOUNT (D-12/D-76 precedent). The documented contract:
 *
 *   Base URL   https://api.emailonacid.com/v5
 *   Auth       HTTP Basic, API user : API key
 *   Clients    GET  /email/clients
 *              → {"clients": {"OL2016": {"client":…, "os":…, "category":…}}}
 *   Create     POST /email/tests
 *                {"subject": …, "html": …, "text": …, "clients": ["OL2016", …]}
 *              → {"id": "abc123"}
 *   Poll       GET  /email/tests/{id}/results
 *              → {"OL2016": {"id":…, "status": "Complete|Processing|Bounced",
 *                            "screenshots": {"default": url, "thumb": url},
 *                            "display_name":…, "os":…, "category":…}}
 *
 * A real Email on Acid account on an API-enabled plan is required for any of
 * this to return a screenshot; without one {@see configured()} is false and
 * the registry hands out NullProvider. Every shape above is locked by
 * Http::fake tests — those prove the adapter's side of the contract, never
 * the vendor's.
 */
final class EmailOnAcidProvider implements InboxPreviewProvider
{
    public const KEY = 'email_on_acid';

    public function __construct(private readonly InboxPreviewSettings $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('inbox_preview.providers.email_on_acid');
    }

    public function configured(): bool
    {
        return trim((string) $this->settings->email_on_acid_api_user) !== ''
            && trim((string) $this->settings->email_on_acid_api_key) !== '';
    }

    public function clients(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $response = $this->http()->get('/email/clients');

            if (! $response->successful()) {
                return [];
            }

            $clients = [];

            foreach ((array) ($response->json('clients') ?? []) as $key => $client) {
                $clients[(string) $key] = trim(implode(' ', array_filter([
                    (string) ($client['client'] ?? $key),
                    (string) ($client['os'] ?? ''),
                ])));
            }

            return $clients;
        } catch (Throwable) {
            return [];
        }
    }

    public function submit(string $subject, string $html, ?string $text, array $clients = []): string
    {
        if (! $this->configured()) {
            throw new InboxPreviewException(__('inbox_preview.errors.not_configured'));
        }

        $selected = $clients !== [] ? array_values($clients) : $this->settings->emailOnAcidClientList();

        $payload = array_filter([
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            // An empty list means "the account's default client set" — the
            // vendor picks, we do not invent one.
            'clients' => $selected === [] ? null : $selected,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $response = $this->http()->post('/email/tests', $payload);
        } catch (Throwable $exception) {
            throw new InboxPreviewException($exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new InboxPreviewException(__('inbox_preview.errors.vendor', [
                'provider' => $this->label(),
                'status' => (string) $response->status(),
                'body' => mb_substr(trim($response->body()), 0, 200),
            ]));
        }

        $id = $response->json('id');

        if ($id === null || $id === '') {
            throw new InboxPreviewException(__('inbox_preview.errors.no_id', ['provider' => $this->label()]));
        }

        return (string) $id;
    }

    public function results(string $remoteId): InboxPreviewResult
    {
        if (! $this->configured()) {
            return InboxPreviewResult::failed(__('inbox_preview.errors.not_configured'));
        }

        try {
            $response = $this->http()->get('/email/tests/'.rawurlencode($remoteId).'/results');
        } catch (Throwable $exception) {
            return InboxPreviewResult::failed($exception->getMessage());
        }

        if (! $response->successful()) {
            return InboxPreviewResult::failed(__('inbox_preview.errors.vendor', [
                'provider' => $this->label(),
                'status' => (string) $response->status(),
                'body' => mb_substr(trim($response->body()), 0, 200),
            ]));
        }

        $screens = [];

        foreach ((array) $response->json() as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $shots = (array) ($row['screenshots'] ?? []);

            $screens[] = new InboxPreviewScreen(
                client: (string) $key,
                label: (string) ($row['display_name'] ?? $row['client'] ?? $key),
                os: isset($row['os']) ? (string) $row['os'] : null,
                category: isset($row['category']) ? (string) $row['category'] : null,
                state: $this->state((string) ($row['status'] ?? '')),
                imageUrl: $this->url($shots['default'] ?? $shots['full'] ?? null),
                thumbnailUrl: $this->url($shots['thumb'] ?? $shots['thumbnail'] ?? null),
            );
        }

        return InboxPreviewResult::from($screens);
    }

    /* ---------------------------------------------------------------- */

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->settings->emailOnAcidBaseUrl())
            ->withBasicAuth(
                (string) $this->settings->email_on_acid_api_user,
                (string) $this->settings->email_on_acid_api_key,
            )
            ->acceptJson()
            ->timeout((int) config('emp-campaigns.inbox_preview.timeout_seconds', 30));
    }

    private function state(string $vendorStatus): string
    {
        return match (mb_strtolower(trim($vendorStatus))) {
            'complete', 'completed' => InboxPreviewScreen::STATE_COMPLETE,
            'bounced', 'error', 'failed' => InboxPreviewScreen::STATE_FAILED,
            default => InboxPreviewScreen::STATE_PROCESSING,
        };
    }

    private function url(mixed $value): ?string
    {
        $url = trim((string) (is_array($value) ? ($value['url'] ?? '') : $value));

        return $url === '' ? null : $url;
    }
}
