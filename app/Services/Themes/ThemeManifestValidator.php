<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Settings\SkinSettings;

/**
 * Validates a decoded `theme.json` (spec §6.3 F-6.3-005).
 *
 * Mirrors PluginManifestValidator's contract — return a list of human
 * messages, empty means valid — so both packaging surfaces report failures
 * the same way and one Filament notification helper can render either.
 *
 * The slug is a DIRECTORY NAME, which is why it gets an allowlist regex, a
 * length bound and a reserved-name list rather than "whatever the author
 * typed": everything after this point joins it onto a filesystem path.
 */
class ThemeManifestValidator
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const SLUG_MIN = 3;

    public const SLUG_MAX = 64;

    /** @return list<string> */
    public function validate(ThemeManifest $manifest): array
    {
        $errors = [];

        array_push($errors, ...$this->validateSlug($manifest));
        array_push($errors, ...$this->validateName($manifest));
        array_push($errors, ...$this->validateVersion($manifest));
        array_push($errors, ...$this->validateStylesheet($manifest));
        array_push($errors, ...$this->validateAreas($manifest));
        array_push($errors, ...$this->validatePalette($manifest));
        array_push($errors, ...$this->validateScheme($manifest));

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function validateSlug(ThemeManifest $manifest): array
    {
        $slug = $manifest->slug;

        if ($slug === '') {
            return [__('themes.errors.manifest_slug_missing')];
        }

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return [__('themes.errors.manifest_slug_invalid', ['slug' => $slug])];
        }

        if (mb_strlen($slug) < self::SLUG_MIN || mb_strlen($slug) > self::SLUG_MAX) {
            return [__('themes.errors.manifest_slug_length', [
                'min' => self::SLUG_MIN,
                'max' => self::SLUG_MAX,
            ])];
        }

        $reserved = array_map('strval', (array) config('emp-themes.reserved_slugs', []));

        if (in_array($slug, $reserved, true) || ThemePalettes::has($slug)) {
            return [__('themes.errors.manifest_slug_reserved', ['slug' => $slug])];
        }

        return [];
    }

    /** @return list<string> */
    private function validateName(ThemeManifest $manifest): array
    {
        if ($manifest->name === '') {
            return [__('themes.errors.manifest_name_missing')];
        }

        return mb_strlen($manifest->name) > 191
            ? [__('themes.errors.manifest_name_too_long')]
            : [];
    }

    /** @return list<string> */
    private function validateVersion(ThemeManifest $manifest): array
    {
        return preg_match('/^\d+(\.\d+){0,3}(-[A-Za-z0-9.]+)?$/', $manifest->version) === 1
            ? []
            : [__('themes.errors.manifest_version_invalid', ['version' => $manifest->version])];
    }

    /**
     * The stylesheet is opened by the renderer, so it must be a plain
     * relative `.css` path inside the package — no traversal, no absolute
     * path, no drive letter, no URL.
     *
     * @return list<string>
     */
    private function validateStylesheet(ThemeManifest $manifest): array
    {
        $path = str_replace('\\', '/', $manifest->stylesheet);

        if ($path === '') {
            return [__('themes.errors.manifest_stylesheet_missing')];
        }

        if (str_contains($path, '..') || str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:#', $path) === 1
            || str_contains($path, '://') || str_contains($path, "\0")) {
            return [__('themes.errors.manifest_stylesheet_unsafe', ['path' => $manifest->stylesheet])];
        }

        return mb_strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'css'
            ? []
            : [__('themes.errors.manifest_stylesheet_not_css', ['path' => $manifest->stylesheet])];
    }

    /** @return list<string> */
    private function validateAreas(ThemeManifest $manifest): array
    {
        if ($manifest->areas === []) {
            // Omitted = "skins every area", which is the sane default for a
            // pure palette. Not an error.
            return [];
        }

        $unknown = array_diff($manifest->areas, SkinSettings::AREAS);

        return $unknown === []
            ? []
            : [__('themes.errors.manifest_area_unknown', ['areas' => implode(', ', $unknown)])];
    }

    /** @return list<string> */
    private function validatePalette(ThemeManifest $manifest): array
    {
        $errors = [];

        foreach ($manifest->palette as $token => $value) {
            if (! in_array($token, ThemePalettes::TOKENS, true)) {
                $errors[] = __('themes.errors.manifest_token_unknown', ['token' => $token]);

                continue;
            }

            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($value)) !== 1) {
                $errors[] = __('themes.errors.manifest_token_not_hex', [
                    'token' => $token,
                    'value' => $value,
                ]);
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateScheme(ThemeManifest $manifest): array
    {
        return in_array($manifest->scheme, ['light', 'dark'], true)
            ? []
            : [__('themes.errors.manifest_scheme_invalid', ['scheme' => $manifest->scheme])];
    }
}
