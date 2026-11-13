<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\Campaign;
use App\Models\EmailList;

/**
 * The single renderer behind every campaign PREVIEW surface (spec §2.3.3
 * F-2.3.3-020 preview, F-2.3.3-008 conditionals, F-2.3.3-009 smart content).
 *
 * Before WP-46 the preview modals called the sampler-grade
 * App\Services\Templates\MergeTags::renderSample(), which does a flat strtr()
 * of the 20 seeded tags. That is fine for a template thumbnail and wrong for a
 * campaign: it leaves `[IF …] … [ENDIF]` blocks in the output verbatim, so a
 * customer previewing conditional or segment content sees the syntax instead
 * of the result. This class routes previews through the canonical WP-13 engine
 * (D-22/D-62 order: conditionals → tags → spintax) with SAMPLE values, so the
 * preview shows what the recipient will see.
 *
 * A preview has no recipient, so segment predicates evaluate as MATCHED — see
 * SegmentContent for why, and {@see notice()} for the caption that says so.
 */
final class CampaignPreviewRenderer
{
    public function __construct(
        private readonly MergeTags $mergeTags,
        private readonly Spintax $spintax,
        private readonly SegmentContent $segments,
    ) {}

    public function render(Campaign $campaign): string
    {
        $html = (string) $campaign->content_html;

        if (trim($html) === '') {
            return '';
        }

        /** @var EmailList|null $list */
        $list = $campaign->list()->withoutGlobalScopes()->first();

        return $this->spintax->render(
            $this->mergeTags->render($html, $this->mergeTags->sampleValues($campaign, $list)),
            '',
        );
    }

    /**
     * Segment references the body uses, for the preview caption. Empty when
     * the campaign has no smart content, so the caption stays out of the way.
     *
     * @return list<string>
     */
    public function segmentReferences(Campaign $campaign): array
    {
        return $this->segments->referencesIn((string) $campaign->content_html);
    }
}
