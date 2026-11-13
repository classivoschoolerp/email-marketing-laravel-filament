<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use App\Services\I18n\Locales;
use Illuminate\Database\Seeder;

/**
 * WP-20 — registers the shipped language packs (spec §6.5 F-6.5-002:
 * English + Italian + Japanese, the Acelle parity set). Idempotent;
 * is_default mirrors CommonSettings::default_language afterwards.
 */
class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'en', 'name' => 'English', 'rtl' => false],
            ['code' => 'it', 'name' => 'Italiano', 'rtl' => false],
            ['code' => 'ja', 'name' => '日本語', 'rtl' => false],
        ] as $language) {
            Language::query()->updateOrCreate(
                ['code' => $language['code']],
                ['name' => $language['name'], 'rtl' => $language['rtl'], 'enabled' => true],
            );
        }

        Locales::syncDefaultFlag();
    }
}
