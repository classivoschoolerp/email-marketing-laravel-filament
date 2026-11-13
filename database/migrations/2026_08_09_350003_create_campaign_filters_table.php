<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign recipient filters (spec §2.3.7, WP-35).
 *
 * Each row is one engagement predicate against ANOTHER campaign:
 * opened / not_opened / clicked / not_clicked (F-2.3.7-001/002), optionally
 * narrowed to a single tracked link (`campaign_track_url_id`).
 *
 * `mode` carries the include/exclude axis (F-2.3.7-003): includes are combined
 * with the campaign's `filter_match` (all = AND, any = OR); excludes are always
 * subtracted afterwards (AND NOT of the OR of every exclude). That is the only
 * combination that makes "include (opened A or opened B), exclude clicked C"
 * expressible, and it is applied inside CampaignRecipients — the single choke
 * point the count preview, the send wave and the API all read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);

            // opened | not_opened | clicked | not_clicked
            $table->string('type', 24);

            // include | exclude
            $table->string('mode', 8)->default('include');

            // The campaign being measured. Deliberately NOT a foreign key with
            // cascade: a deleted target must behave like D-90 (matches nothing)
            // rather than silently widening the audience by vanishing.
            $table->unsignedBigInteger('target_campaign_id')->nullable();

            // Optional specific link (F-2.3.7-002).
            $table->unsignedBigInteger('campaign_track_url_id')->nullable();

            $table->timestamps();

            $table->index(['campaign_id', 'position']);
            $table->index('target_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_filters');
    }
};
