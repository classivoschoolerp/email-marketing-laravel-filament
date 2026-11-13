<?php

declare(strict_types=1);

namespace App\Services\I18n;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Language pack upload (spec §3.22 F-3.22-001 / §6.5 F-6.5-004, WP-20):
 * validates a zip of lang group files (*.php returning arrays, and/or
 * *.json) and extracts them to lang/{code}/. Entries may sit at the zip
 * root or under a single top-level folder (both common pack layouts).
 * Anything else — traversal names, other extensions, php files that are
 * not plain `return [...]` translation files — is rejected.
 */
class LanguagePackImporter
{
    /**
     * @return int number of files extracted
     *
     * @throws ValidationException on an invalid archive
     */
    public function import(string $zipPath, string $code, ?string $langPath = null): int
    {
        $code = $this->sanitizeCode($code);
        $target = ($langPath ?? lang_path()).DIRECTORY_SEPARATOR.$code;

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw ValidationException::withMessages(['file' => __('i18n.upload.invalid_zip')]);
        }

        try {
            $files = $this->collectEntries($zip);

            if ($files === []) {
                throw ValidationException::withMessages(['file' => __('i18n.upload.no_lang_files')]);
            }

            if (! is_dir($target)) {
                mkdir($target, 0755, true);
            }

            foreach ($files as $index => $basename) {
                file_put_contents(
                    $target.DIRECTORY_SEPARATOR.$basename,
                    (string) $zip->getFromIndex($index),
                );
            }
        } finally {
            $zip->close();
        }

        return count($files);
    }

    /**
     * "Create new pack" (spec §6.5 F-6.5-004): copies the reference locale's
     * lang files to lang/{code}/ as the translation starting point.
     *
     * @return int number of files copied
     *
     * @throws ValidationException
     */
    public function createFromReference(string $code, ?string $langPath = null): int
    {
        $code = $this->sanitizeCode($code);
        $base = $langPath ?? lang_path();
        $reference = (string) config('app.fallback_locale', 'en');
        $source = $base.DIRECTORY_SEPARATOR.$reference;
        $target = $base.DIRECTORY_SEPARATOR.$code;

        $files = glob($source.DIRECTORY_SEPARATOR.'*.php') ?: [];

        if ($files === []) {
            throw ValidationException::withMessages(['code' => __('i18n.upload.no_reference')]);
        }

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        foreach ($files as $file) {
            copy($file, $target.DIRECTORY_SEPARATOR.basename($file));
        }

        return count($files);
    }

    /**
     * @return array<int, string> zip entry index => safe basename
     *
     * @throws ValidationException
     */
    private function collectEntries(ZipArchive $zip): array
    {
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === '' || str_ends_with($name, '/')) {
                continue; // directory entry
            }

            // Reject traversal / absolute paths outright (defense in depth —
            // we only ever write the basename anyway).
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
                throw ValidationException::withMessages(['file' => __('i18n.upload.unsafe_entry', ['entry' => $name])]);
            }

            $parts = explode('/', str_replace('\\', '/', $name));

            if (count($parts) > 2) {
                throw ValidationException::withMessages(['file' => __('i18n.upload.nested_entry', ['entry' => $name])]);
            }

            $basename = end($parts);
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

            if (! in_array($extension, ['php', 'json'], true)) {
                throw ValidationException::withMessages(['file' => __('i18n.upload.bad_extension', ['entry' => $name])]);
            }

            $content = (string) $zip->getFromIndex($i);

            if ($extension === 'php' && ! $this->looksLikeLangFile($content)) {
                throw ValidationException::withMessages(['file' => __('i18n.upload.bad_php', ['entry' => $name])]);
            }

            if ($extension === 'json' && json_decode($content, true) === null) {
                throw ValidationException::withMessages(['file' => __('i18n.upload.bad_json', ['entry' => $name])]);
            }

            $files[$i] = $basename;
        }

        return $files;
    }

    /**
     * A lang file must open with <?php and contain an actual `return`
     * statement (token-level check — a `return` inside a string does not
     * count), with no inline HTML around the PHP.
     */
    private function looksLikeLangFile(string $content): bool
    {
        if (! str_starts_with(ltrim($content), '<?php')) {
            return false;
        }

        $hasReturn = false;

        foreach (token_get_all($content) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_RETURN) {
                $hasReturn = true;
            }

            if ($token[0] === T_INLINE_HTML && trim($token[1]) !== '') {
                return false;
            }
        }

        return $hasReturn;
    }

    private function sanitizeCode(string $code): string
    {
        $code = Str::lower(trim($code));

        // Reject rather than silently rewrite — a code like "../evil" must
        // never become a write path.
        if (preg_match('/^[a-z0-9_\-]{1,12}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => __('i18n.upload.bad_code')]);
        }

        return $code;
    }
}
