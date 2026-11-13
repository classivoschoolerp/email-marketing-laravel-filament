<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which test-group subscriber received which variant (spec §2.3.6, WP-35).
 *
 * Written by StartCampaignSendJob at split time and never rewritten — the
 * assignment is the durable record that lets the per-variant stats table
 * (F-2.3.6-009) attribute an open/click back to a variant. `subscriber_uid`
 * is duplicated deliberately: campaign_track_opens / campaign_track_clicks are
 * keyed by uid, so the attribution join needs no subscribers hop.
 *
 * unique(campaign_id, subscriber_id) is the idempotency arbiter: a resume
 * wave re-derives the same deterministic split and the insert is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_variant_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_variant_id')->constrained('campaign_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('subscriber_id');
            $table->string('subscriber_uid', 36);
            $table->timestamp('created_at')->nullable();

            $table->unique(['campaign_id', 'subscriber_id']);
            $table->index(['campaign_variant_id', 'subscriber_uid'], 'campaign_variant_recipients_variant_uid_index');
            $table->index('subscriber_uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_variant_recipients');
    }
};
