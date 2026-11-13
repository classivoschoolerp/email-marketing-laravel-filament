<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Export as HTML or ZIP (spec §2.5.1 / §2.5.5 F-2.5.5-007).
 *
 * Exports run synchronously in the download request: template HTML plus its
 * asset directory are bounded by the upload limits (≤ 20 MB per
 * emp-templates.zip.max_size_mb), well under a request budget. Anything
 * larger would arrive via a future bulk-export job on Queues::EXPORTS.
 */
class TemplateExporter
{
    public function fileName(EmailTemplate $template, string $extension): string
    {
        $slug = Str::slug($template->name) ?: 'template';

        return $slug.'.'.$extension;
    }

    public function html(EmailTemplate $template): string
    {
        return (string) $template->content_html;
    }

    /**
     * Build a ZIP (index.html + assets/) in a temp file and return its path.
     * Asset URLs pointing at the template's storage directory are rewritten to
     * the relative assets/ folder so the archive is self-contained.
     */
    public function zip(EmailTemplate $template): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl-export-');

        if ($path === false) {
            throw new RuntimeException('Could not allocate a temp file for the export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the export archive.');
        }

        $disk = Storage::disk('public');
        $dir = $template->assetDirectory();
        $html = (string) $template->content_html;

        foreach ($disk->allFiles($dir) as $file) {
            $relative = 'assets/'.ltrim(Str::after($file, $dir), '/');
            $zip->addFromString($relative, (string) $disk->get($file));

            // Both full URLs and bare storage paths → relative archive path.
            $html = str_replace([$disk->url($file), $file], $relative, $html);
        }

        $zip->addFromString('index.html', $html);
        $zip->close();

        return $path;
    }
}
