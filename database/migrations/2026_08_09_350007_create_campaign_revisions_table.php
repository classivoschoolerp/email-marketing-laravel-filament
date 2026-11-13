<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign content version history / autosave (spec §2.3.3 F-2.3.3-019).
 *
 * Deliberately the same shape as `template_revisions` (§2.5.4 F-2.5.4-008) so
 * CampaignRevisions can mirror TemplateRevisions verbatim: one row per content
 * save, pruned to the newest N, restore records the pre-restore state first so
 * a restore is itself undoable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();

            // manual | autosave | restore — the UI labels the entry honestly.
            $table->string('origin', 16)->default('manual');

            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_revisions');
    }
};
