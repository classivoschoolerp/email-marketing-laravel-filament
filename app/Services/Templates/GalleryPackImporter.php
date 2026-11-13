<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\EmailTemplate;
use App\Models\TemplateCategory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Bulk gallery pack import (spec §3.14 F-3.14-003).
 *
 * Pack format (documented choice — folder structure, NOT zip-of-zips):
 *   pack.zip
 *   ├─ welcome-email/
 *   │   ├─ index.html          (required — or the first *.html in the folder)
 *   │   ├─ template.json       (optional: {"name","category","featured","sort_order"})
 *   │   └─ *.png|jpg|...       (images, extracted + refs rewritten)
 *   └─ another-template/…
 *
 * Each top-level folder becomes ONE system template (customer_id NULL,
 * unpublished — the admin publishes to the gallery explicitly, F-3.14-002).
 * Categories named in template.json are found-or-created as system categories.
 * Root-level files are ignored. Runs inside the queued ImportGalleryPackJob
 * (Queues::IMPORTS) — pack size is unbounded, so never synchronous.
 */
class GalleryPackImporter
{
    public function __construct(
        private readonly TemplateZipImporter $zipImporter,
        private readonly TemplateThumbnails $thumbnails,
    ) {}

    /**
     * @return list<EmailTemplate> the created system templates
     *
     * @throws ValidationException
     */
    public function import(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw ValidationException::withMessages(['file' => __('templates.errors.zip_unreadable')]);
        }

        try {
            $folders = $this->groupByFolder($zip);

            if ($folders === []) {
                throw ValidationException::withMessages(['file' => __('templates.errors.pack_empty')]);
            }

            $created = [];

            foreach ($folders as $folder => $entries) {
                $template = $this->importFolder($zip, $folder, $entries);

                if ($template !== null) {
                    $created[] = $template;
                }
            }

            return $created;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, list<string>> top-level folder => entry names */
    private function groupByFolder(ZipArchive $zip): array
    {
        $folders = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === '' || str_ends_with($name, '/') || ! Str::contains($name, '/')) {
                continue;
            }

            if (Str::contains($name, '..') || Str::startsWith($name, ['/', '\\']) || preg_match('/^[a-zA-Z]:/', $name)) {
                throw ValidationException::withMessages(['file' => __('templates.errors.zip_unsafe_entry')]);
            }

            $folders[Str::before($name, '/')][] = $name;
        }

        return $folders;
    }

    /** @param list<string> $entries */
    private function importFolder(ZipArchive $zip, string $folder, array $entries): ?EmailTemplate
    {
        $htmlEntry = null;
        $meta = [];
        $imageEntries = [];
        $allowed = config('emp-templates.zip.image_extensions', []);

        foreach ($entries as $entry) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $base = strtolower(pathinfo($entry, PATHINFO_FILENAME));

            if (in_array($ext, ['html', 'htm'], true)) {
                if ($htmlEntry === null || $base === 'index') {
                    $htmlEntry = $entry;
                }
            } elseif ($ext === 'json' && $base === 'template') {
                $meta = (array) json_decode((string) $zip->getFromName($entry), true);
            } elseif (in_array($ext, $allowed, true)) {
                $imageEntries[] = $entry;
            }
        }

        if ($htmlEntry === null) {
            return null; // folders without HTML are skipped, not fatal
        }

        $template = new EmailTemplate;
        $template->forceFill([
            'customer_id' => null,
            'name' => Str::limit((string) ($meta['name'] ?? Str::headline($folder)), 250, ''),
            'builder' => 'html',
            'category_id' => $this->systemCategoryId($meta['category'] ?? null),
            'is_gallery' => false, // published explicitly via F-3.14-002
            'is_featured' => (bool) ($meta['featured'] ?? false),
            'sort_order' => (int) ($meta['sort_order'] ?? 0),
        ]);
        $template->save();

        $disk = Storage::disk('public');
        $dir = $template->assetDirectory();
        $html = (string) $zip->getFromName($htmlEntry);
        $replacements = [];

        foreach ($imageEntries as $entry) {
            $relative = Str::after($entry, $folder.'/');
            $stored = $dir.'/'.$relative;
            $disk->put($stored, (string) $zip->getFromName($entry));

            $url = $disk->url($stored);
            $replacements[$relative] = $url;
            $replacements['./'.$relative] = $url;
        }

        $html = $this->zipImporter->rewriteReferences($html, $replacements);

        $template->forceFill(['content_html' => $html])->save();
        $this->thumbnails->refreshFromContent($template);

        return $template->refresh();
    }

    private function systemCategoryId(mixed $name): ?int
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $category = TemplateCategory::withoutTenancy()
            ->whereNull('customer_id')
            ->where('name', trim($name))
            ->first();

        if ($category === null) {
            $category = new TemplateCategory;
            $category->forceFill(['customer_id' => null, 'name' => trim($name)]);
            $category->save();
        }

        return (int) $category->id;
    }
}
