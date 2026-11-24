<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingPage;
use App\Models\LandingPageRevision;
use App\Models\LandingPageVariant;
use App\Services\Templates\TemplateRevisions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Landing-page revision history with restore (spec §2.6 F-2.6-005).
 *
 * A deliberate mirror of {@see TemplateRevisions}:
 * one row per content save, pruned to the newest
 * `config('emp-templates.revisions_keep')` (the same 20 the template builder
 * keeps — one knob, not two), and a restore that records the pre-restore
 * state FIRST so restoring is itself undoable.
 *
 * The one extension is the variant dimension: an A/B arm has its own
 * document, so its history is pruned independently of the page's. Pruning per
 * document rather than per page is what stops a busy variant from evicting
 * the page's own history.
 */
class LandingPageRevisions
{
    public function record(LandingPage $page, ?LandingPageVariant $variant = null): LandingPageRevision
    {
        $this->assertBelongs($page, $variant);

        /** @var LandingPageRevision $revision */
        $revision = LandingPageRevision::query()->create([
            'landing_page_id' => $page->id,
            'landing_page_variant_id' => $variant?->id,
            'content_html' => $variant === null ? $page->content_html : $variant->content_html,
            'content_json' => $variant === null ? $page->content_json : $variant->content_json,
            'created_at' => Carbon::now(),
        ]);

        $this->prune($page, $variant);

        return $revision;
    }

    public function prune(LandingPage $page, ?LandingPageVariant $variant = null): void
    {
        $keep = max(1, (int) config('emp-templates.revisions_keep', 20));

        $staleIds = $this->documentQuery($page, $variant)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            LandingPageRevision::query()->whereIn('id', $staleIds)->delete();
        }
    }

    /** Restore a stored document; the current one is recorded first. */
    public function restore(LandingPage $page, LandingPageRevision $revision): void
    {
        abort_unless((int) $revision->landing_page_id === (int) $page->id, 404);

        $variant = $revision->landing_page_variant_id === null
            ? null
            : LandingPageVariant::withoutTenancy()
                ->where('landing_page_id', $page->id)
                ->whereKey($revision->landing_page_variant_id)
                ->first();

        // A revision whose variant has since been deleted must not silently
        // overwrite the PAGE's content — that would be a different document.
        abort_if($revision->landing_page_variant_id !== null && $variant === null, 404);

        $this->record($page, $variant);

        $target = $variant ?? $page;
        $target->forceFill([
            'content_html' => $revision->content_html,
            'content_json' => $revision->content_json,
        ])->save();
    }

    /** @return Builder<LandingPageRevision> */
    private function documentQuery(LandingPage $page, ?LandingPageVariant $variant)
    {
        return LandingPageRevision::query()
            ->where('landing_page_id', $page->id)
            ->when(
                $variant === null,
                fn ($query) => $query->whereNull('landing_page_variant_id'),
                fn ($query) => $query->where('landing_page_variant_id', $variant->id),
            );
    }

    private function assertBelongs(LandingPage $page, ?LandingPageVariant $variant): void
    {
        abort_unless(
            $variant === null || (int) $variant->landing_page_id === (int) $page->id,
            404,
        );
    }
}
