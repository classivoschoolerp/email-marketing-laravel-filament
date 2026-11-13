<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Customer;
use App\Models\Domain;
use App\Services\Settings\SettingsReader;
use App\Services\Themes\ThemePalettes;
use App\Settings\SkinSettings;
use App\Support\Billing\PlanBranding;
use Illuminate\Http\Request;
use Throwable;

/**
 * Resolves the brand for a request or a customer (spec §4.7, §6.3, §6.4).
 *
 * PRECEDENCE, highest first:
 *   1. the §6.4 `domains` row matching the request HOST (multi-brand install);
 *   2. the customer's OWN sign-up domain, when the caller passes a customer
 *      but no host (queued mail has no request);
 *   3. the WP-26 plan brand for that customer (D-82, resolved live from the
 *      subscription — never copied, so it un-brands when the plan lapses);
 *   4. the install-wide SkinSettings/CommonSettings branding (§4.7 F-4.7-001).
 *
 * Every layer is a partial override: a domain that sets only a logo still
 * inherits the install colours. That is what makes "run five brands from one
 * install" survivable — a new brand needs one row, not a full theme.
 *
 * Every read is Throwable-guarded. Brand resolution runs on the login screen
 * and on the installer, i.e. before the settings tables necessarily exist,
 * and a missing table must degrade to the app name — never to a 500 that
 * locks the operator out of the panel that would fix it.
 */
class BrandResolver
{
    /** @var array<string, ?Domain> */
    private array $hostCache = [];

    /**
     * The brand a request should be served with.
     */
    public function forRequest(Request $request): Brand
    {
        return $this->build($this->domainForHost($request->getHost()), null);
    }

    /**
     * The brand a given host maps to, ignoring any current request. Used by
     * the CMS host fence and by tests.
     */
    public function forHost(?string $host): Brand
    {
        return $this->build($this->domainForHost($host), null);
    }

    /**
     * The brand a customer belongs to. Used by queued mail and by the
     * customer panel, where the request host may be the app host even though
     * the account lives under a brand.
     */
    public function forCustomer(?Customer $customer): Brand
    {
        return $this->build($this->domainForCustomer($customer), $customer);
    }

    /**
     * The §6.4 domain row a host maps to, or null for the app host / an
     * unknown host. Memoised per instance: the middleware, the views and the
     * CMS fence all ask the same question inside one request.
     */
    public function domainForHost(?string $host): ?Domain
    {
        $host = Domain::normalizeHost((string) $host);

        if ($host === '') {
            return null;
        }

        if (array_key_exists($host, $this->hostCache)) {
            return $this->hostCache[$host];
        }

        try {
            $domain = Domain::query()->where('host', $host)->where('active', true)->first();
        } catch (Throwable) {
            return $this->hostCache[$host] = null;
        }

        return $this->hostCache[$host] = $domain;
    }

    /**
     * Host fence (§6.4 F-6.4-002), the WP-34 LandingPageUrl::servableOnHost()
     * pattern applied to brand-owned content.
     *
     * A row whose `domain_id` is NULL is brand-agnostic and serves anywhere.
     * A row bound to brand A serves ONLY on brand A's host — including when
     * the visitor reaches it on the app host, because otherwise every brand's
     * private terms page would be readable at the operator's own address.
     */
    public function servableOnHost(?int $domainId, ?string $host): bool
    {
        if ($domainId === null) {
            return true;
        }

        return $this->domainForHost($host)?->id === $domainId;
    }

    public function appHost(): string
    {
        return mb_strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
    }

    private function domainForCustomer(?Customer $customer): ?Domain
    {
        if ($customer === null || $customer->domain_id === null) {
            return null;
        }

        try {
            return Domain::query()->whereKey($customer->domain_id)->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function build(?Domain $domain, ?Customer $customer): Brand
    {
        $skin = $this->skin();
        $common = $this->common();
        $plan = $this->planBrand($customer);
        // §4.7 F-4.7-004 (WP-46) — the customer's OWN branding, agency mode.
        $agency = $this->agencyBrand($customer);

        // Precedence, deliberate: the operator's brand DOMAIN always wins —
        // an operator who assigned a brand host has made a decision about
        // that host and a tenant must not repaint it. The customer's own
        // agency branding sits next, above the plan's white label: the plan
        // GRANTS the capability, the customer EXERCISES it (F-4.7-004).
        $name = $this->firstFilled([
            $domain?->name,
            $agency['product_name'] ?? null,
            $plan['plan_name'] ?? null,
            $skin['product_name'] ?? null,
            $common['site_name'] ?? null,
        ]) ?? (string) config('app.name');

        return new Brand(
            name: $name,
            themeSlug: $this->firstFilled([
                $domain?->theme,
                $skin['theme_frontend'] ?? null,
            ]) ?? ThemePalettes::DEFAULT,
            logoPath: $this->firstFilled([
                $domain?->logo_path,
                $agency['logo'] ?? null,
                $plan['logo'] ?? null,
                $common['logo_path'] ?? null,
            ]),
            faviconPath: $this->firstFilled([
                $domain?->favicon_path,
                $agency['favicon'] ?? null,
                $common['favicon_path'] ?? null,
            ]),
            loginBackgroundPath: $this->firstFilled([
                $domain?->login_background_path,
                $skin['login_background'] ?? null,
            ]),
            primaryColor: $this->hex($this->firstFilled([
                $domain?->primary_color,
                $agency['primary'] ?? null,
                $plan['primary'] ?? null,
                $skin['primary'] ?? null,
            ])),
            secondaryColor: $this->hex($this->firstFilled([
                $domain?->secondary_color,
                $agency['secondary'] ?? null,
                $plan['accent'] ?? null,
                $skin['secondary'] ?? null,
            ])),
            fromName: $this->firstFilled([
                $domain?->from_name,
                $agency['from_name'] ?? null,
                $plan['from_name'] ?? null,
            ]),
            fromEmail: $this->firstFilled([$domain?->from_email]),
            replyToEmail: $this->firstFilled([$domain?->reply_to_email]),
            domain: $domain,
            hideVendorBranding: (bool) ($skin['hide_branding'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function skin(): array
    {
        try {
            $reader = SettingsReader::skin();

            return [
                'primary' => $reader->brandPrimaryColor(),
                'secondary' => $reader->brandSecondaryColor(),
                'login_background' => $reader->loginBackgroundPath(),
                'hide_branding' => $reader->hideBranding(),
                'product_name' => $reader->hasCustomProductName() ? $reader->productName() : null,
                'theme_frontend' => $reader->skin(SkinSettings::AREA_FRONTEND),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function common(): array
    {
        try {
            $reader = SettingsReader::common();

            return [
                'site_name' => $reader->siteName(),
                'logo_path' => $reader->logoPath(),
                'favicon_path' => $reader->faviconPath(),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function planBrand(?Customer $customer): array
    {
        if ($customer === null || ! class_exists(PlanBranding::class)) {
            return [];
        }

        try {
            $brand = PlanBranding::forCustomer($customer);
        } catch (Throwable) {
            return [];
        }

        if ($brand === null) {
            return [];
        }

        return [
            'plan_name' => $brand['from_name'] ?? $brand['plan'],
            'from_name' => $brand['from_name'] ?? $brand['plan'],
            'primary' => $brand['primary'],
            'accent' => $brand['accent'],
            'logo' => $brand['logo'],
        ];
    }

    /**
     * §4.7 F-4.7-004 — the customer's OWN branding (agency mode).
     *
     * Gated on the {@see AgencyBranding::PERMISSION} quota key (D-42), so the
     * capability is something an operator grants per group/plan rather than
     * something every tenant gets. A customer whose entitlement lapses stops
     * branding immediately and needs no cleanup — the same live-resolution
     * property D-82 gives plan branding.
     *
     * @return array<string, mixed>
     */
    private function agencyBrand(?Customer $customer): array
    {
        if ($customer === null || ! class_exists(AgencyBranding::class)) {
            return [];
        }

        try {
            return app(AgencyBranding::class)->forCustomer($customer);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, string|null>  $candidates
     */
    private function firstFilled(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** Literal hex only — the value is interpolated into a style tag (D-82). */
    private function hex(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : null;
    }
}
