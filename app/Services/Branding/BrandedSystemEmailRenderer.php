<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Services\Settings\SettingsReader;
use App\Services\SystemEmails\RenderedSystemEmail;
use App\Services\SystemEmails\SystemEmailRenderer;
use Throwable;

/**
 * Per-domain system email templates (spec §6.4 F-6.4-002 "…from-addresses,
 * email templates…", WP-38).
 *
 * A SUBCLASS bound over {@see SystemEmailRenderer} in the container rather
 * than an edit to WP-20's renderer: every consumer already resolves the
 * renderer through the container, so binding this extends all 18 of them at
 * once and none of them changes.
 *
 * It adds exactly one layer to D-69's precedence chain, on top and per brand:
 *
 *   brand override  →  install-wide DB override (enabled)  →  code default
 *
 * D-69's other rules survive unchanged: a blank column still falls through
 * per column, and "no override" never means "do not send".
 *
 * [APP_NAME] is re-bound to the brand's own name, because a brand's welcome
 * email signed with the operator's product name is the exact white-label
 * leak §4.7 exists to prevent.
 *
 * Tag substitution here escapes every value with `e()`, matching the parent's
 * private tagMap(). A system-email template is admin-authored but its tag
 * VALUES are not — a subscriber-supplied name must never become markup.
 */
class BrandedSystemEmailRenderer extends SystemEmailRenderer
{
    public function __construct(private readonly BrandContext $brands) {}

    /**
     * @param  array<string, string|int|float|null>  $tags
     */
    public function resolve(string $key, array $tags = []): RenderedSystemEmail
    {
        $base = parent::resolve($key, $tags);

        $override = $this->brandTemplate($key);
        $brandName = $this->brandName();

        if ($override === null && $brandName === null) {
            return $base;
        }

        if ($override === null) {
            // No per-brand template, but the brand renames [APP_NAME]. Rerun
            // the parent with the tag supplied so its own escaping applies.
            return parent::resolve($key, $tags + ['APP_NAME' => $brandName]);
        }

        $map = $this->map($tags, $brandName);

        return new RenderedSystemEmail(
            $override['subject'] === null ? $base->subject : strtr($override['subject'], $map),
            $override['body'] === null ? $base->html : strtr($override['body'], $map),
        );
    }

    /**
     * @return array{subject: ?string, body: ?string}|null
     */
    private function brandTemplate(string $key): ?array
    {
        try {
            return $this->brands->current()->domain?->emailTemplate($key);
        } catch (Throwable) {
            return null; // pre-migration boot (installer)
        }
    }

    /** The brand's own name, or null when this is not a §6.4 brand. */
    private function brandName(): ?string
    {
        try {
            $brand = $this->brands->current();
        } catch (Throwable) {
            return null;
        }

        return $brand->isDomainBrand() ? $brand->name : null;
    }

    /**
     * Mirrors the parent's private tagMap(), including the `e()` escaping.
     *
     * @param  array<string, string|int|float|null>  $tags
     * @return array<string, string>
     */
    private function map(array $tags, ?string $brandName): array
    {
        $map = [];

        foreach ($tags as $name => $value) {
            $map['['.strtoupper((string) $name).']'] = e((string) ($value ?? ''));
        }

        // [APP_NAME] is always bound — a brand template that uses the tag must
        // never render the literal "[APP_NAME]" just because this brand has no
        // name override. Mirrors the parent's private appName() fallback chain.
        if (! array_key_exists('[APP_NAME]', $map)) {
            $map['[APP_NAME]'] = e($brandName ?? $this->installName());
        }

        return $map;
    }

    private function installName(): string
    {
        try {
            $name = SettingsReader::common()->siteName();

            if (trim($name) !== '') {
                return $name;
            }
        } catch (Throwable) {
            // settings table not migrated yet
        }

        return (string) config('app.name');
    }
}
