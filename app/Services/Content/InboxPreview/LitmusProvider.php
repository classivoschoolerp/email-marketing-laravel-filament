<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

use App\Settings\InboxPreviewSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Litmus Instant Previews adapter (spec §2.3.5 F-2.3.5-006, WP-46).
 *
 * IMPLEMENTED AGAINST LITMUS'S PUBLISHED API, NOT AGAINST A LIVE ACCOUNT
 * (D-12/D-76 precedent). The contract below is what the vendor documents:
 *
 *   Base URL   https://{subdomain}.litmus.com/api/v1
 *   Auth       HTTP Basic, the API key as the username
 *   Create     POST /emails.json
 *                {"email": {"subject": …, "html_text": …, "plain_text": …,
 *                           "test_set": "populardesktop"}}
 *              → 201 {"id": 12345, "test_set_versions": [{ "results": [...] }]}
 *   Poll       GET  /emails/{id}.json
 *              → each result: {"id", "state": "complete|processing|failed",
 *                              "full_image", "thumbnail_image",
 *                              "application_long_name", "os_long_name",
 *                              "email_client", "result_type"}
 *   Clients    GET  /clients.json → [{"application_long_name", "id"}]
 *
 * A real Litmus account on an Instant Previews plan is required for any of
 * this to return a screenshot; without one {@see configured()} is false and
 * the registry hands out NullProvider. Every request/response shape above is
 * locked by Http::fake tests — those prove the adapter's side of the contract,
 * never Litmus's.
 */
final class LitmusProvider implements InboxPreviewProvider
{
    public const KEY = 'litmus';

    public function __construct(private readonly InboxPreviewSettings $settings) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('inbox_preview.providers.litmus');
    }

    public function configured(): bool
    {
        return trim((string) $this->settings->litmus_subdomain) !== ''
            && trim((string) $this->settings->litmus_api_key) !== '';
    }

    public function clients(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $response = $this->http()->get('/clients.json');

            if (! $response->successful()) {
                return [];
            }

            $clients = [];

            foreach ((array) $response->json() as $client) {
                $id = (string) ($client['id'] ?? $client['application_code'] ?? '');
                $name = (string) ($client['application_long_name'] ?? $id);

                if ($id !== '') {
                    $clients[$id] = $name;
                }
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

        $payload = [
            'email' => array_filter([
                'subject' => $subject,
                'html_text' => $html,
                'plain_text' => $text,
                // Litmus takes a NAMED test set, or an explicit application
                // list; an empty selection means "the account default".
                'test_set' => $clients === [] ? $this->settings->litmus_test_set : null,
                'applications' => $clients === [] ? null : array_values($clients),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        try {
            $response = $this->http()->post('/emails.json', $payload);
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
            $response = $this->http()->get('/emails/'.rawurlencode($remoteId).'.json');
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

        $versions = (array) ($response->json('test_set_versions') ?? []);
        $rows = (array) ($versions[0]['results'] ?? []);
        $screens = [];

        foreach ($rows as $row) {
            $screens[] = new InboxPreviewScreen(
                client: (string) ($row['email_client'] ?? $row['id'] ?? ''),
                label: (string) ($row['application_long_name'] ?? $row['email_client'] ?? ''),
                os: isset($row['os_long_name']) ? (string) $row['os_long_name'] : null,
                category: isset($row['result_type']) ? (string) $row['result_type'] : null,
                state: $this->state((string) ($row['state'] ?? '')),
                imageUrl: $this->url($row['full_image'] ?? null),
                thumbnailUrl: $this->url($row['thumbnail_image'] ?? null),
            );
        }

        return InboxPreviewResult::from($screens);
    }

    /* ---------------------------------------------------------------- */

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->settings->litmusBaseUrl())
            // Litmus authenticates with the API key as the Basic username;
            // the password is ignored and conventionally left blank.
            ->withBasicAuth((string) $this->settings->litmus_api_key, '')
            ->acceptJson()
            ->timeout((int) config('emp-campaigns.inbox_preview.timeout_seconds', 30));
    }

    private function state(string $vendorState): string
    {
        return match (mb_strtolower(trim($vendorState))) {
            'complete', 'completed' => InboxPreviewScreen::STATE_COMPLETE,
            'error', 'failed' => InboxPreviewScreen::STATE_FAILED,
            default => InboxPreviewScreen::STATE_PROCESSING,
        };
    }

    private function url(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['url'] ?? null;
        }

        $url = trim((string) $value);

        return $url === '' ? null : $url;
    }
}
