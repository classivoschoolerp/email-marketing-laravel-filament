<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plain-English campaign performance summary
 * (spec §2.15.8 F-2.15.8-008, WP-46).
 *
 * A side table rather than a column on `campaigns`, for two reasons:
 *
 *  - D-54 says campaign OPTIONS are typed columns; this is not an option, it
 *    is a generated artefact with its own provenance (engine, model, credits,
 *    when) that only exists for campaigns somebody asked about;
 *  - it is the cache that makes the feature honest about money. The summary is
 *    written ONCE per explicit request and read on every report render, so
 *    opening a report never bills a credit. Regenerating is a separate,
 *    deliberate click.
 *
 * `stats` is the exact fact set the prose was written about. Keeping it means
 * the UI can say "written from these numbers" and an operator can audit that
 * the model was never asked to compute anything (D-105).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_ai_summaries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // One live summary per campaign; regenerating replaces it.
            $table->foreignId('campaign_id')->unique()->constrained('campaigns')->cascadeOnDelete();

            $table->text('summary');

            // The numbers the prose was written about, exactly as supplied.
            $table->json('stats')->nullable();

            $table->string('engine', 64)->nullable();
            $table->string('model', 190)->nullable();

            // What this cost, so the report can show it (F-2.15.8-010).
            $table->unsignedInteger('credits')->default(0);

            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_ai_summaries');
    }
};
