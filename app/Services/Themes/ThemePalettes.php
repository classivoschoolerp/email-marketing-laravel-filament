<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Settings\SkinSettings;

/**
 * The built-in themes (spec §6.3 F-6.3-001).
 *
 * Acelle ships seven named skins — dark, light, red, blue, green, pink,
 * brown — plus the product default. All eight are here as PALETTES rather
 * than as stylesheet files, because a palette is what actually differs
 * between them: the layout, spacing and components are Filament's and the
 * public site's, and forking those per colour scheme would be eight copies of
 * the same CSS to maintain.
 *
 * A palette becomes CSS custom properties on :root (see {@see ThemeCss}), so
 * both panels and the public frontend consume the same tokens.
 *
 * `classic` and `modern` are the two placeholder slugs WP-05 shipped in
 * SkinSettings::SKINS. They are kept — and given real palettes — because an
 * installation may already have one selected, and silently invalidating a
 * stored setting is a data-loss bug, not a cleanup.
 */
final class ThemePalettes
{
    public const DEFAULT = 'default';

    /** Colour tokens every palette must define. */
    public const TOKENS = [
        'primary',
        'primary_contrast',
        'accent',
        'surface',
        'surface_alt',
        'text',
        'text_muted',
        'border',
    ];

    /**
     * Slug => [scheme, tokens]. `scheme` drives `color-scheme` so form
     * controls and scrollbars match the palette instead of fighting it.
     *
     * @var array<string, array{scheme: string, tokens: array<string, string>}>
     */
    private const PALETTES = [
        'default' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#4f46e5',
                'primary_contrast' => '#ffffff',
                'accent' => '#6366f1',
                'surface' => '#ffffff',
                'surface_alt' => '#f9fafb',
                'text' => '#111827',
                'text_muted' => '#6b7280',
                'border' => '#e5e7eb',
            ],
        ],
        'dark' => [
            'scheme' => 'dark',
            'tokens' => [
                'primary' => '#818cf8',
                'primary_contrast' => '#0b1020',
                'accent' => '#a5b4fc',
                'surface' => '#111827',
                'surface_alt' => '#1f2937',
                'text' => '#f9fafb',
                'text_muted' => '#9ca3af',
                'border' => '#374151',
            ],
        ],
        'light' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#0f172a',
                'primary_contrast' => '#ffffff',
                'accent' => '#334155',
                'surface' => '#ffffff',
                'surface_alt' => '#f1f5f9',
                'text' => '#0f172a',
                'text_muted' => '#64748b',
                'border' => '#e2e8f0',
            ],
        ],
        'red' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#dc2626',
                'primary_contrast' => '#ffffff',
                'accent' => '#f87171',
                'surface' => '#ffffff',
                'surface_alt' => '#fef2f2',
                'text' => '#1f2937',
                'text_muted' => '#7f1d1d',
                'border' => '#fecaca',
            ],
        ],
        'blue' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#2563eb',
                'primary_contrast' => '#ffffff',
                'accent' => '#60a5fa',
                'surface' => '#ffffff',
                'surface_alt' => '#eff6ff',
                'text' => '#1e293b',
                'text_muted' => '#1e40af',
                'border' => '#bfdbfe',
            ],
        ],
        'green' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#059669',
                'primary_contrast' => '#ffffff',
                'accent' => '#34d399',
                'surface' => '#ffffff',
                'surface_alt' => '#ecfdf5',
                'text' => '#14342b',
                'text_muted' => '#065f46',
                'border' => '#a7f3d0',
            ],
        ],
        'pink' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#db2777',
                'primary_contrast' => '#ffffff',
                'accent' => '#f472b6',
                'surface' => '#ffffff',
                'surface_alt' => '#fdf2f8',
                'text' => '#3f1229',
                'text_muted' => '#9d174d',
                'border' => '#fbcfe8',
            ],
        ],
        'brown' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#92400e',
                'primary_contrast' => '#ffffff',
                'accent' => '#d97706',
                'surface' => '#fffbf5',
                'surface_alt' => '#fef3c7',
                'text' => '#3f2d1c',
                'text_muted' => '#78350f',
                'border' => '#fde68a',
            ],
        ],

        // WP-05 legacy slugs — kept valid so a stored selection never breaks.
        'classic' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#1f2937',
                'primary_contrast' => '#ffffff',
                'accent' => '#4b5563',
                'surface' => '#ffffff',
                'surface_alt' => '#f3f4f6',
                'text' => '#111827',
                'text_muted' => '#6b7280',
                'border' => '#d1d5db',
            ],
        ],
        'modern' => [
            'scheme' => 'light',
            'tokens' => [
                'primary' => '#0891b2',
                'primary_contrast' => '#ffffff',
                'accent' => '#22d3ee',
                'surface' => '#ffffff',
                'surface_alt' => '#ecfeff',
                'text' => '#0f172a',
                'text_muted' => '#155e75',
                'border' => '#a5f3fc',
            ],
        ],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::PALETTES);
    }

    public static function has(string $slug): bool
    {
        return array_key_exists($slug, self::PALETTES);
    }

    /** @return array<string, string> */
    public static function tokens(string $slug): array
    {
        return self::PALETTES[$slug]['tokens'] ?? self::PALETTES[self::DEFAULT]['tokens'];
    }

    public static function scheme(string $slug): string
    {
        return self::PALETTES[$slug]['scheme'] ?? 'light';
    }

    /**
     * Built-in themes as {@see Theme} value objects.
     *
     * @return array<string, Theme>
     */
    public static function themes(): array
    {
        $out = [];

        foreach (self::PALETTES as $slug => $palette) {
            $out[$slug] = new Theme(
                slug: $slug,
                name: __('themes.builtin.'.$slug),
                source: Theme::SOURCE_BUILTIN,
                palette: $palette['tokens'],
                scheme: $palette['scheme'],
                // A built-in supports every area: it is only colour tokens.
                areas: SkinSettings::AREAS,
                version: (string) config('app.version', '1.0.0'),
            );
        }

        return $out;
    }
}
