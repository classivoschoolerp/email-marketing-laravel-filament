<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Models\Customer;
use App\Models\User;
use App\Services\Branding\BrandContext;
use App\Services\Settings\SettingsReader;
use App\Settings\SkinSettings;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Decides which theme an area renders with (spec §6.3 F-6.3-001/002/006).
 *
 * PRECEDENCE, highest first:
 *   1. the signed-in account's OWN choice (`users.theme` / `customers.theme`)
 *      — F-6.3-002, "each admin/customer picks their own";
 *   2. the brand serving this host (§6.4 domain row, or the plan brand);
 *   3. the install-wide SkinSettings slug for that area (WP-05 F-3.25.8-001);
 *   4. the built-in default.
 *
 * A stale slug — a package the operator deleted — silently degrades to the
 * default (ThemeRegistry::resolve) rather than 500-ing every page, and an
 * account whose chosen theme does not support the area it is looking at falls
 * through to the next layer instead of being served a half-styled panel.
 */
class ThemeResolver
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly BrandContext $brands,
    ) {}

    /**
     * The effective theme for an area, optionally for a specific account.
     */
    public function forArea(string $area, ?Authenticatable $account = null): Theme
    {
        if (! in_array($area, SkinSettings::AREAS, true)) {
            $area = SkinSettings::AREA_FRONTEND;
        }

        foreach ($this->candidates($area, $account) as $slug) {
            if ($slug === null || $slug === '') {
                continue;
            }

            $theme = $this->registry->get($slug);

            if ($theme !== null && $theme->supportsArea($area)) {
                return $theme;
            }
        }

        return $this->registry->default();
    }

    /**
     * The theme slug stored on an account, or null when it follows the
     * install/brand default.
     */
    public function preferenceFor(Authenticatable $account): ?string
    {
        if (! ($account instanceof User || $account instanceof Customer)) {
            return null;
        }

        $slug = trim((string) ($account->theme ?? ''));

        return $slug === '' ? null : $slug;
    }

    /**
     * Persist an account's own theme choice. NULL clears it, which is what
     * "follow the default" has to mean — otherwise an operator who re-brands
     * the install never reaches anyone who once picked a theme.
     */
    public function setPreference(Authenticatable $account, ?string $slug): void
    {
        if (! ($account instanceof User || $account instanceof Customer)) {
            return;
        }

        $slug = trim((string) $slug);

        if ($slug !== '' && ! $this->registry->has($slug)) {
            $slug = '';
        }

        $account->forceFill(['theme' => $slug === '' ? null : $slug])->save();
    }

    /**
     * @return list<string|null>
     */
    private function candidates(string $area, ?Authenticatable $account): array
    {
        $accountSlug = $account === null ? null : $this->preferenceFor($account);

        return [
            $accountSlug,
            $this->brandSlug(),
            $this->settingSlug($area),
        ];
    }

    private function brandSlug(): ?string
    {
        try {
            $domain = $this->brands->current()->domain;
        } catch (Throwable) {
            return null;
        }

        $slug = trim((string) ($domain?->theme ?? ''));

        return $slug === '' ? null : $slug;
    }

    private function settingSlug(string $area): ?string
    {
        try {
            return SettingsReader::skin()->skin($area);
        } catch (Throwable) {
            return null; // settings not migrated yet (installer / first boot)
        }
    }
}
