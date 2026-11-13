<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — one row per address checked by a verification run (§2.9.12).
 * Source of the downloadable result CSV (F-2.9.12-008) and the drill-down
 * history (F-2.9.12-009). subscriber_id is nullable so single-address
 * (real-time) checks can be logged without a subscriber row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('verification_job_id')->constrained('verification_jobs')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->string('email');
            // deliverable|undeliverable|risky|unknown
            $table->string('status', 16)->index();
            $table->string('reason')->nullable();
            $table->string('provider', 32);

            $table->timestamps();

            $table->index(['verification_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_results');
    }
};
