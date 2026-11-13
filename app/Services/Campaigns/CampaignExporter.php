<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Export a campaign's content as HTML or a self-contained ZIP (spec §2.3.9
 * F-2.3.9-007) — same shape as the template exporter (WP-10): index.html +
 * assets/, with storage URLs rewritten to relative archive paths.
 */
class CampaignExporter
{
    public function fileName(Campaign $campaign, string $extension): string
    {
        $slug = Str::slug($campaign->name) ?: 'campaign';

        return $slug.'.'.$extension;
    }

    public function html(Campaign $campaign): string
    {
        return (string) $campaign->content_html;
    }

    /** Build the ZIP in a temp file and return its path. */
    public function zip(Campaign $campaign): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cmp-export-');

        if ($path === false) {
            throw new RuntimeException('Could not allocate a temp file for the export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the export archive.');
        }

        $disk = Storage::disk('public');
        $dir = $campaign->assetDirectory();
        $html = (string) $campaign->content_html;

        foreach ($disk->allFiles($dir) as $file) {
            $relative = 'assets/'.ltrim(Str::after($file, $dir), '/');
            $zip->addFromString($relative, (string) $disk->get($file));

            $html = str_replace([$disk->url($file), $file], $relative, $html);
        }

        $zip->addFromString('index.html', $html);
        $zip->close();

        return $path;
    }
}
