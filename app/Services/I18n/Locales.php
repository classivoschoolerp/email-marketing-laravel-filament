<?php

declare(strict_types=1);

namespace App\Services\I18n;

use App\Models\Language;
use App\Settings\CommonSettings;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Language-pack metadata helper (spec §3.22 / §6.5, WP-20).
 *
 * Default-language contract: CommonSettings::default_language (§3.25.1,
 * WP-04/05 owned) is the single source of truth; languages.is_default is a
 * mirror maintained by syncDefaultFlag() so the admin grid can show it.
 * setDefault() writes the settings VALUE through the container-bound
 * settings class (no app/Settings file is touched) and then re-syncs the
 * mirror.
 *
 * Every read is guarded: before the WP-20 migration (installer, early boot)
 * the languages table may not exist — helpers then degrade to "en/LTR".
 */
class Locales
{
    /** @return Collection<int, Language> */
    public static function installed(): Collection
    {
        try {
            return Language::query()->orderBy('code')->get();
        } catch (Throwable) {
            return new Collection;
        }
    }

    /** @return array<string, string> code => name, enabled packs only */
    public static function enabledOptions(): array
    {
        return self::installed()
            ->filter(fn (Language $language): bool => $language->enabled)
            ->mapWithKeys(fn (Language $language): array => [$language->code => $language->name])
            ->all();
    }

    /**
     * Is the locale usable for rendering? Enabled row wins; with no
     * languages table (pre-migration) any locale with a lang/ directory —
     * or the fallback locale — is accepted.
     */
    public static function isUsable(string $locale): bool
    {
        if ($locale === '') {
            return false;
        }

        try {
            $row = Language::query()->where('code', $locale)->first();

            if ($row !== null) {
                return $row->enabled;
            }
        } catch (Throwable) {
            // table missing — fall through to the filesystem check
        }

        return $locale === (string) config('app.fallback_locale', 'en')
            || is_dir(lang_path($locale));
    }

    public static function isRtl(string $locale): bool
    {
        try {
            return (bool) Language::query()->where('code', $locale)->value('rtl');
        } catch (Throwable) {
            return false;
        }
    }

    /** dir="" attribute value for html elements (F-3.22-003 / F-6.5-006). */
    public static function direction(string $locale): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }

    /** The installation default (CommonSettings §3.25.1; guarded). */
    public static function defaultCode(): string
    {
        try {
            $code = app(CommonSettings::class)->default_language;

            if (trim($code) !== '') {
                return $code;
            }
        } catch (Throwable) {
            // settings not migrated yet
        }

        return (string) config('app.locale', 'en');
    }

    /**
     * Set the installation default: writes the CommonSettings VALUE (the
     * source of truth) and mirrors it onto languages.is_default.
     */
    public static function setDefault(string $code): void
    {
        $settings = app(CommonSettings::class);
        $settings->default_language = $code;
        $settings->save();

        self::syncDefaultFlag();
    }

    /** Re-mirror languages.is_default from CommonSettings (idempotent). */
    public static function syncDefaultFlag(): void
    {
        try {
            $default = self::defaultCode();

            Language::query()->where('is_default', true)->where('code', '!=', $default)
                ->update(['is_default' => false]);
            Language::query()->where('code', $default)->where('is_default', false)
                ->update(['is_default' => true]);
        } catch (Throwable) {
            // table missing — nothing to mirror
        }
    }
}
