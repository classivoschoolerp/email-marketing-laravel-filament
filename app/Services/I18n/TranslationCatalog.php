<?php

declare(strict_types=1);

namespace App\Services\I18n;

use App\Models\TranslationOverride;
use Illuminate\Support\Arr;

/**
 * The translation editor's data layer (spec §6.5 F-6.5-003, WP-20): scans
 * the reference locale's lang files for groups/keys, computes effective
 * values (override > locale file > reference file) and persists overrides —
 * dropping the row again when the admin saves a value identical to the file
 * string, so the overrides table only ever holds real deviations.
 */
class TranslationCatalog
{
    /** The locale whose files define the canonical key set. */
    public function referenceLocale(): string
    {
        return (string) config('app.fallback_locale', 'en');
    }

    /** @return list<string> translation groups = lang/{ref}/*.php basenames */
    public function groups(): array
    {
        $files = glob(lang_path($this->referenceLocale()).DIRECTORY_SEPARATOR.'*.php') ?: [];

        $groups = array_map(
            static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
            $files,
        );

        sort($groups);

        return array_values($groups);
    }

    /**
     * Flattened dot-key => file string for one locale+group (no overrides).
     *
     * @return array<string, string>
     */
    public function fileStrings(string $locale, string $group): array
    {
        $path = lang_path($locale.DIRECTORY_SEPARATOR.$group.'.php');

        if (! is_file($path)) {
            return [];
        }

        $lines = require $path;

        if (! is_array($lines)) {
            return [];
        }

        return array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            Arr::dot($lines),
        );
    }

    /**
     * Effective editor rows for locale+group: every reference key, with the
     * override > locale file > reference file value.
     *
     * @return array<string, string>
     */
    public function effective(string $locale, string $group): array
    {
        $reference = $this->fileStrings($this->referenceLocale(), $group);
        $local = $this->fileStrings($locale, $group);
        $overrides = $this->overrides($locale, $group);

        $rows = [];

        foreach ($reference as $key => $referenceValue) {
            $rows[$key] = $overrides[$key] ?? $local[$key] ?? $referenceValue;
        }

        return $rows;
    }

    /** @return array<string, string> stored override rows for locale+group */
    public function overrides(string $locale, string $group): array
    {
        return TranslationOverride::query()
            ->where('locale', $locale)
            ->where('group', $group)
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * Persist the editor state: values differing from the effective file
     * string become override rows; values matching it drop any override.
     *
     * @param  array<string, string>  $values  dot-key => value
     * @return int number of stored overrides after the save
     */
    public function save(string $locale, string $group, array $values): int
    {
        $reference = $this->fileStrings($this->referenceLocale(), $group);
        $local = $this->fileStrings($locale, $group);

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $reference)) {
                continue; // unknown key — never store junk
            }

            $fileValue = $local[$key] ?? $reference[$key];

            if ((string) $value === $fileValue || trim((string) $value) === '') {
                TranslationOverride::query()
                    ->where('locale', $locale)->where('group', $group)->where('key', $key)
                    ->delete();

                continue;
            }

            TranslationOverride::query()->updateOrCreate(
                ['locale' => $locale, 'group' => $group, 'key' => $key],
                ['value' => (string) $value],
            );
        }

        $this->flush();

        return TranslationOverride::query()->where('locale', $locale)->where('group', $group)->count();
    }

    /** Drop the Translator's per-request group cache so edits show at once. */
    public function flush(): void
    {
        app('translator')->setLoaded([]);
    }
}
