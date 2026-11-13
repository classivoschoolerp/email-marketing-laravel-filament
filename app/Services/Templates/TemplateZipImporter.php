<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Template ZIP upload (spec §2.5.5 F-2.5.5-001).
 *
 * Expected archive: one HTML file (index.html preferred, otherwise the first
 * *.html/*.htm) plus image files, optionally inside a single top-level folder.
 * Images are extracted to the template's public storage directory
 * (customers/{uid}/templates/{uid}/ — spec §0.2) and relative references in
 * src/href/background/url(...) are rewritten to the public storage URLs.
 *
 * Runs synchronously: uploads are capped at emp-templates.zip.max_size_mb
 * (= sync threshold, default 20 MB). Bulk gallery packs go through the queued
 * ImportGalleryPackJob instead.
 */
class TemplateZipImporter
{
    /**
     * Extract into the given (already created) template row. WP-11 reuse:
     * campaign content uploads (spec §2.3.3 F-2.3.3-005) go through the same
     * importer — Campaign shares the assetDirectory()/content_html contract.
     *
     * @throws ValidationException on malformed archives
     */
    public function import(EmailTemplate|Campaign $template, string $zipPath): EmailTemplate|Campaign
    {
        // WP-22 (§3.25.15 F-3.25.15-006): the ONE representative upload path
        // wired to the central UploadGuard — extension allowlist, hard size
        // cap and the configured virus scanner. Defaults accept `zip` at
        // 20 MB with scanning off, so nothing changes until an operator
        // tightens the Security settings page.
        $errors = app(\App\Services\Security\UploadGuard::class)
            ->checkPath($zipPath, basename($zipPath));

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors[0]]);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw ValidationException::withMessages(['file' => __('templates.errors.zip_unreadable')]);
        }

        try {
            [$htmlEntry, $imageEntries] = $this->scan($zip);

            if ($htmlEntry === null) {
                throw ValidationException::withMessages(['file' => __('templates.errors.zip_no_html')]);
            }

            $html = (string) $zip->getFromName($htmlEntry);
            $prefix = Str::contains($htmlEntry, '/') ? Str::beforeLast($htmlEntry, '/').'/' : '';

            $disk = Storage::disk('public');
            $dir = $template->assetDirectory();
            $replacements = [];

            foreach ($imageEntries as $entry) {
                $relative = Str::after($entry, $prefix);
                $stored = $dir.'/'.$this->safeRelativePath($relative);
                $disk->put($stored, (string) $zip->getFromName($entry));

                $url = $disk->url($stored);
                // Rewrite both "images/x.png" and "./images/x.png" style refs.
                $replacements[$relative] = $url;
                $replacements['./'.$relative] = $url;
            }

            $html = $this->rewriteReferences($html, $replacements);

            $template->forceFill(['content_html' => $html])->save();

            return $template;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{0: ?string, 1: list<string>} [html entry, image entries]
     */
    private function scan(ZipArchive $zip): array
    {
        $htmlEntry = null;
        $images = [];
        $allowed = config('emp-templates.zip.image_extensions', []);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            // Zip-slip protection: reject traversal and absolute paths outright.
            if (Str::contains($name, '..') || Str::startsWith($name, ['/', '\\']) || preg_match('/^[a-zA-Z]:/', $name)) {
                throw ValidationException::withMessages(['file' => __('templates.errors.zip_unsafe_entry')]);
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (in_array($ext, ['html', 'htm'], true)) {
                $isIndex = in_array(strtolower(pathinfo($name, PATHINFO_FILENAME)), ['index'], true);

                if ($htmlEntry === null || $isIndex) {
                    $htmlEntry = $name;
                }
            } elseif (in_array($ext, $allowed, true)) {
                $images[] = $name;
            }
        }

        return [$htmlEntry, $images];
    }

    /** @param array<string, string> $replacements relative ref => absolute URL */
    public function rewriteReferences(string $html, array $replacements): string
    {
        if ($replacements === []) {
            return $html;
        }

        // Longest keys first so "images/a.png" never clobbers "sub/images/a.png".
        uksort($replacements, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($replacements as $relative => $url) {
            $quoted = preg_quote($relative, '~');

            // src="..." href="..." background="..." (single or double quotes)
            $html = (string) preg_replace(
                '~(\s(?:src|href|background)=["\'])'.$quoted.'(["\'])~i',
                '$1'.$url.'$2',
                $html
            );

            // CSS url(...) with or without quotes
            $html = (string) preg_replace(
                '~url\(\s*["\']?'.$quoted.'["\']?\s*\)~i',
                'url('.$url.')',
                $html
            );
        }

        return $html;
    }

    private function safeRelativePath(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            fn (string $s): bool => $s !== '' && $s !== '.' && $s !== '..'
        );

        return implode('/', $segments);
    }
}
