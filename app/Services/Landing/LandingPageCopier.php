<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Enums\LandingPageStatus;
use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Duplicate a landing page (spec §2.6 F-2.6-014).
 *
 * A copy is a DRAFT with a fresh slug, no publish timestamps and no history:
 * duplicating an already published page must never put a second document live
 * on a URL nobody chose, and the copy's stats start empty because they are
 * facts about the original's traffic, not the copy's.
 *
 * Variants come along (a duplicated test is a test) with their winner flag
 * cleared — the winner was decided by the original's data. Uploaded assets
 * are copied into the new page's own directory and the URLs inside the
 * content are rewritten, so the copy survives deletion of its source; that is
 * the §2.5.1 TemplateCopier rule, applied here for the same reason.
 */
class LandingPageCopier
{
    public function __construct(private readonly LandingPageRevisions $revisions) {}

    public function duplicate(LandingPage $source, ?string $name = null): LandingPage
    {
        $name = $name ?? Str::limit((string) $source->name, 170, '').' '.__('landing.copy_suffix');

        $copy = $source->replicate(['uid', 'slug', 'deleted_at']);
        $copy->uid = (string) Str::uuid();
        $copy->name = $name;
        $copy->slug = LandingPage::uniqueSlug($name, null);
        $copy->forceFill([
            'status' => LandingPageStatus::Draft,
            'published_at' => null,
            'scheduled_at' => null,
        ]);
        $copy->save();

        $this->copyAssets($source, $copy);
        $this->copyVariants($source, $copy);

        // Give the copy a first restore point of its own.
        $this->revisions->record($copy->refresh());

        return $copy->refresh();
    }

    private function copyVariants(LandingPage $source, LandingPage $copy): void
    {
        $sourceDir = $source->assetDirectory();
        $copyDir = $copy->assetDirectory();

        foreach (LandingPageVariant::withoutTenancy()->where('landing_page_id', $source->id)->orderBy('id')->get() as $variant) {
            LandingPageVariant::withoutTenancy()->create([
                'customer_id' => $copy->customer_id,
                'landing_page_id' => $copy->id,
                'name' => $variant->name,
                'weight' => $variant->weight,
                'content_html' => $this->rewrite($variant->content_html, $sourceDir, $copyDir),
                'content_json' => $this->rewrite($variant->content_json, $sourceDir, $copyDir),
                'is_control' => (bool) $variant->is_control,
                // The winner belonged to the original's data, not to the copy.
                'is_winner' => false,
                'enabled' => (bool) $variant->enabled,
            ]);
        }
    }

    private function copyAssets(LandingPage $source, LandingPage $copy): void
    {
        $disk = Storage::disk('public');
        $sourceDir = $source->assetDirectory();
        $copyDir = $copy->assetDirectory();

        foreach ($disk->allFiles($sourceDir) as $file) {
            $disk->copy($file, $copyDir.Str::after($file, $sourceDir));
        }

        $copy->forceFill([
            'content_html' => $this->rewrite($copy->content_html, $sourceDir, $copyDir),
            'content_json' => $this->rewrite($copy->content_json, $sourceDir, $copyDir),
        ])->saveQuietly();
    }

    private function rewrite(?string $value, string $from, string $to): ?string
    {
        return is_string($value) && $value !== '' ? str_replace($from, $to, $value) : $value;
    }
}
