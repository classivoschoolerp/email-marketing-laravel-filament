<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-34 — landing-page revision history (spec §2.6 F-2.6-005).
 *
 * Deliberately the same shape as `template_revisions` (§2.5.4 F-2.5.4-008):
 * a row per content save, pruned to the newest N, and a restore that records
 * the pre-restore state first so the restore is itself undoable. A variant's
 * content lives on its own row, so `landing_page_variant_id` says which
 * document the revision belongs to (NULL = the page itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id')->constrained('landing_pages')->cascadeOnDelete();
            $table->foreignId('landing_page_variant_id')->nullable()
                ->constrained('landing_page_variants')->cascadeOnDelete();

            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['landing_page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_revisions');
    }
};
