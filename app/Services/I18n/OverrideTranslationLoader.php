<?php

declare(strict_types=1);

namespace App\Services\I18n;

use App\Models\TranslationOverride;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Decorator over Laravel's translation FileLoader (spec §6.5 F-6.5-003,
 * WP-20): merges translation_overrides rows on top of the lang/ file
 * strings, so the in-app editor can change any string without touching
 * files — upgrade-safe by design. Registered via
 * `$app->extend('translation.loader')` in I18nServiceProvider.
 *
 * Guarded: with no translation_overrides table (pre-migration / installer)
 * the file strings pass through untouched. Namespaced (vendor) groups are
 * never overridden. The Translator caches loaded groups per request; the
 * editor calls TranslationCatalog::flush() after saving.
 */
class OverrideTranslationLoader implements Loader
{
    public function __construct(private readonly Loader $base) {}

    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->base->load($locale, $group, $namespace);

        if ($namespace !== null && $namespace !== '*') {
            return $lines; // vendor namespace — not editable
        }

        try {
            $overrides = TranslationOverride::query()
                ->where('locale', (string) $locale)
                ->where('group', (string) $group)
                ->get(['key', 'value']);
        } catch (Throwable) {
            return $lines; // table missing (installer safety)
        }

        foreach ($overrides as $override) {
            if ($group === '*') {
                // JSON strings: keys are literal sentences — no dot syntax.
                $lines[$override->key] = $override->value;
            } else {
                Arr::set($lines, $override->key, $override->value);
            }
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->base->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->base->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->base->namespaces();
    }
}
