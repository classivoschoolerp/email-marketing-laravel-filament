<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\Customer;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripo plugin adapter (spec §2.5.3 F-2.5.3-003, WP-26).
 *
 * Stripo is an embedded third-party editor. Its documented plugin protocol
 * has exactly three moving parts and this class owns the two server-side
 * ones:
 *
 *  1. AUTH — POST {pluginId, secretKey, userId} to the plugin auth endpoint
 *     and receive a short-lived `token`. This MUST happen server-side: the
 *     secret key never reaches the browser. The token is cached per tenant
 *     for `token_ttl` seconds so re-opening the editor does not hammer
 *     Stripo.
 *  2. INIT CONFIG — the object handed to `Stripo.init(...)` in the browser:
 *     the container ids, the current html/css, a stable `emailId` so Stripo
 *     can key its own per-email data, the locale, and the getAuthToken
 *     callback fed by (1).
 *  3. EXPORT — done in the browser: `StripoApi.compileEmail(cb)` returns the
 *     inlined HTML, which the page POSTs to the existing builder autosave
 *     endpoint (routes/modules/templates.php), so Stripo templates persist
 *     and revision exactly like GrapesJS ones.
 *
 * Availability: without BOTH credentials the driver reports unavailable and
 * BuilderRegistry hides it from every picker — the editor page then refuses
 * to render (403). Enabling Stripo is configuration only, no code change.
 *
 * HONEST STATUS (D-13-style): the token exchange and the init payload are
 * implemented against Stripo's documented plugin API and locked by
 * Http::fake tests, but nobody here holds a Stripo account, so the live
 * round trip (real token → real editor → real compiled HTML) is unverified.
 * It is listed in docs/e2e-checklist.md.
 */
class StripoDriver implements BuilderDriver
{
    public function key(): TemplateBuilder
    {
        return TemplateBuilder::Stripo;
    }

    public function label(): string
    {
        return __('templates.builders.stripo');
    }

    public function isAvailable(): bool
    {
        return $this->pluginId() !== null && $this->secretKey() !== null;
    }

    public function editUrl(EmailTemplate $template): string
    {
        // The embedded editor page only exists while credentials are set;
        // otherwise fall back to the raw-HTML surface so links never 404.
        return $this->isAvailable()
            ? EmailTemplateResource::getUrl('stripo', ['record' => $template], panel: 'customer')
            : EmailTemplateResource::getUrl('edit', ['record' => $template], panel: 'customer');
    }

    /**
     * Step 1 — exchange the plugin credentials for a short-lived token.
     *
     * @throws RuntimeException when unconfigured or Stripo refuses
     */
    public function authToken(?Customer $customer = null): string
    {
        $pluginId = $this->pluginId();
        $secret = $this->secretKey();

        if ($pluginId === null || $secret === null) {
            throw new RuntimeException('Stripo plugin credentials are not configured.');
        }

        $userId = $this->userId($customer);
        $ttl = max(60, (int) config('emp-templates.stripo.token_ttl', 600));

        return Cache::remember(
            'stripo-token:'.sha1($pluginId.'|'.$userId),
            $ttl,
            function () use ($pluginId, $secret, $userId): string {
                $response = Http::acceptJson()->post(
                    (string) config('emp-templates.stripo.auth_url'),
                    ['pluginId' => $pluginId, 'secretKey' => $secret, 'userId' => $userId],
                );

                $token = (string) $response->json('token', '');

                if (! $response->successful() || $token === '') {
                    throw new RuntimeException('Stripo auth failed: '.(string) $response->body());
                }

                return $token;
            },
        );
    }

    /**
     * Step 2 — the payload the editor page hands to `Stripo.init(...)`.
     *
     * @return array<string, mixed>
     */
    public function editorConfig(EmailTemplate $template, ?Customer $customer = null): array
    {
        return [
            'scriptUrl' => (string) config('emp-templates.stripo.script_url'),
            'settingsId' => 'stripoSettingsContainer',
            'previewId' => 'stripoPreviewContainer',
            'html' => (string) ($template->content_html ?? ''),
            'css' => '',
            'locale' => str_replace('_', '-', app()->getLocale()),
            'apiRequestData' => [
                // Stripo keys its own per-email storage on this; the template
                // uid is stable for the life of the template.
                'emailId' => (string) $template->uid,
            ],
            'userFullName' => $customer?->full_name ?: (string) $customer?->email,
            'token' => $this->authToken($customer),
            // The page posts the compiled HTML back through the existing
            // builder autosave route (policy-checked, revisioned).
            'saveUrl' => route('templates.builder.save', ['uid' => $template->uid]),
        ];
    }

    /** Stable, non-guessable per-tenant identity for Stripo's side. */
    private function userId(?Customer $customer): string
    {
        return $customer !== null ? 'customer-'.$customer->uid : 'operator';
    }

    private function pluginId(): ?string
    {
        $value = config('emp-templates.stripo.plugin_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function secretKey(): ?string
    {
        $value = config('emp-templates.stripo.secret_key');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
