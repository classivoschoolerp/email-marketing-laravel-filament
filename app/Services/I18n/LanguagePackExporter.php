<?php

declare(strict_types=1);

namespace App\Services\I18n;

use Illuminate\Support\Arr;
use RuntimeException;
use ZipArchive;

/**
 * Language pack export (spec §6.5 F-6.5-004, WP-20): zips the EFFECTIVE
 * strings of a locale — file values with translation_overrides merged in,
 * every reference key present (untranslated keys fall back to the
 * reference string) — as standard lang group PHP files, so an exported
 * pack round-trips through LanguagePackImporter.
 */
class LanguagePackExporter
{
    public function __construct(private readonly TranslationCatalog $catalog) {}

    /** @return string absolute path of the written zip (caller deletes) */
    public function export(string $locale, ?string $directory = null): string
    {
        $directory ??= storage_path('app'.DIRECTORY_SEPARATOR.'language-packs');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.'lang-'.$locale.'-'.now()->format('Ymd-His').'.zip';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create zip at {$path}");
        }

        foreach ($this->catalog->groups() as $group) {
            $strings = $this->catalog->effective($locale, $group);

            if ($strings === []) {
                continue;
            }

            $zip->addFromString($locale.'/'.$group.'.php', $this->phpFile(Arr::undot($strings)));
        }

        $zip->close();

        return $path;
    }

    /** @param array<string, mixed> $lines */
    private function phpFile(array $lines): string
    {
        // Plain var_export: valid PHP, and no string-munging that could
        // corrupt translations containing PHP-looking text.
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($lines, true).";\n";
    }
}
