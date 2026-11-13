<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Models\Theme as ThemeModel;
use App\Settings\SkinSettings;
use Throwable;

/**
 * The single authority on which theme slugs exist (spec §6.3, §3.21).
 *
 * Built-ins come from code and can never be missing; packages come from the
 * `themes` table and are read through a Throwable guard so an installer or a
 * pre-migration boot resolves the built-in list instead of exploding — the
 * same pre-migration discipline SkinServiceProvider and PlanBranding use.
 *
 * Resolution is memoised per instance; the container binds this as a scoped
 * singleton so one request reads the table at most once.
 */
class ThemeRegistry
{
    /** @var array<string, Theme>|null */
    private ?array $cache = null;

    /**
     * @return array<string, Theme>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $themes = ThemePalettes::themes();

        foreach ($this->packages() as $package) {
            // A package may not shadow a built-in: config('emp-themes.reserved_slugs')
            // blocks that at upload time, and this is the belt-and-braces
            // half in case a row predates the reservation list.
            if (isset($themes[$package->slug])) {
                continue;
            }

            $themes[$package->slug] = $package;
        }

        return $this->cache = $themes;
    }

    public function get(string $slug): ?Theme
    {
        return $this->all()[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return $this->get($slug) !== null;
    }

    /** The theme every fallback ends at; guaranteed to exist. */
    public function default(): Theme
    {
        return $this->all()[ThemePalettes::DEFAULT];
    }

    /**
     * Resolve a slug to a theme, falling back to the default rather than
     * throwing: a stored slug can become stale when a package is deleted,
     * and a deleted theme must degrade to "default", never to a 500 on every
     * page of the panel.
     */
    public function resolve(?string $slug): Theme
    {
        if ($slug === null || $slug === '') {
            return $this->default();
        }

        return $this->get($slug) ?? $this->default();
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_keys($this->all());
    }

    /**
     * Slugs a given area may use (F-3.21-001: admin / customer / frontend are
     * managed independently, so a package may declare it only skins one).
     *
     * @return list<string>
     */
    public function slugsForArea(string $area): array
    {
        return array_values(array_keys(array_filter(
            $this->all(),
            static fn (Theme $theme): bool => $theme->supportsArea($area),
        )));
    }

    /**
     * @return array<string, string> slug => label, for a Filament select
     */
    public function options(?string $area = null): array
    {
        $out = [];

        foreach ($this->all() as $slug => $theme) {
            if ($area !== null && ! $theme->supportsArea($area)) {
                continue;
            }

            $out[$slug] = $theme->isPackage()
                ? $theme->name.' — '.__('themes.source.package')
                : $theme->name;
        }

        return $out;
    }

    /** Drops the memo so a just-installed package is visible immediately. */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @return list<Theme>
     */
    private function packages(): array
    {
        try {
            $rows = ThemeModel::query()->orderBy('name')->get();
        } catch (Throwable) {
            return []; // table not migrated yet (installer / first boot)
        }

        return $rows->map(fn (ThemeModel $row): Theme => new Theme(
            slug: (string) $row->slug,
            name: (string) $row->name,
            source: Theme::SOURCE_PACKAGE,
            // A package need only override what it changes.
            palette: array_merge(ThemePalettes::tokens(ThemePalettes::DEFAULT), $row->paletteTokens()),
            scheme: $row->scheme(),
            areas: $row->supportedAreas() ?: SkinSettings::AREAS,
            version: (string) $row->version,
            author: $row->author,
            description: $row->description,
            stylesheet: (string) $row->stylesheet,
            directory: $row->directory(),
        ))->all();
    }
}
