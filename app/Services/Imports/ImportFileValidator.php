<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Services\Settings\SettingsReader;

/**
 * Enforces the §3.25.2 admin limits on import files (F-2.2.7-015): maximum
 * file size and allowed extensions. Used by the Filament upload UI, the
 * import page's server-side re-check and the URL download inside the queued
 * job — never trust a single layer.
 */
class ImportFileValidator
{
    /**
     * @return list<string> error messages; empty = valid
     */
    public function validate(string $filename, int $sizeBytes): array
    {
        $settings = SettingsReader::importExport();
        $errors = [];

        $extension = strtolower(pathinfo(parse_url($filename, PHP_URL_PATH) ?: $filename, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $settings->allowedImportExtensions());

        if ($extension !== '' && $allowed !== [] && ! in_array($extension, $allowed, true)) {
            $errors[] = __('imports.errors.extension_not_allowed', [
                'extension' => $extension,
                'allowed' => implode(', ', $allowed),
            ]);
        }

        $maxBytes = $settings->maxImportFileSizeMb() * 1024 * 1024;

        if ($maxBytes > 0 && $sizeBytes > $maxBytes) {
            $errors[] = __('imports.errors.file_too_large', [
                'max' => $settings->maxImportFileSizeMb(),
            ]);
        }

        return $errors;
    }
}
