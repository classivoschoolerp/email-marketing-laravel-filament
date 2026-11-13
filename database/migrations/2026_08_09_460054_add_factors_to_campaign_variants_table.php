<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multivariate combinations (spec §2.3.6 F-2.3.6-004, WP-46).
 *
 * `factors` records which LEVEL of each factor a variant carries, e.g.
 * `{"subject": "Free shipping", "content": "hero-a"}`. It is what turns a set
 * of variants into a factorial DESIGN rather than eight unrelated emails, and
 * it is what makes "subject B wins regardless of the image" answerable: the
 * per-factor report aggregates every variant sharing a level.
 *
 * It is stored explicitly rather than derived from the override columns
 * because two factors can legitimately carry the same string, and because a
 * Content factor spans three columns (template_id / content_html /
 * content_json) with no single value to key on.
 *
 * NULL means "not part of a factorial design" — every variant created by the
 * ordinary side-by-side composer (F-2.3.6-001) keeps its existing behaviour,
 * and the per-factor report simply does not appear for those campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_variants', function (Blueprint $table): void {
            $table->json('factors')->nullable()->after('content_json');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_variants', function (Blueprint $table): void {
            $table->dropColumn('factors');
        });
    }
};
