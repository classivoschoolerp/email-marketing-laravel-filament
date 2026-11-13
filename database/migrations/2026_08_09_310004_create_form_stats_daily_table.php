<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — form analytics rollup (spec F-2.7-012).
 *
 * Views are a high-volume, low-value event: one row per (form, day, source)
 * with counters, never one row per impression. Submissions are counted in the
 * same row so the conversion rate is a single read, and grouping by `source`
 * answers "by source" without a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_stats_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->date('stat_date');
            $table->string('source', 64)->default('direct');

            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('submissions')->default(0);

            $table->timestamps();

            $table->unique(['form_id', 'stat_date', 'source'], 'form_stats_daily_unique');
            $table->index(['customer_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_stats_daily');
    }
};
