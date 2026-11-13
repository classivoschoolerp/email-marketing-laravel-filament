<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2.13 F-2.13-007 (lead-scoring rules) and F-2.13-009 (round-robin owner
 * assignment) — WP-44.
 *
 * `crm_lead_score_rules` is the configurable half of the score: one row per
 * rule, an event key from App\Enums\CrmLeadScoreEvent, the points it is worth
 * and a small json config (which tag, which stage). The recompute
 * (App\Services\Crm\LeadScorer) reads these rows and NOTHING else is
 * hard-coded — an account with no rules scores every contact 0 plus its
 * manual adjustment.
 *
 * `crm_round_robin_state` is a single integer cursor per (tenant, scope). It
 * is a table rather than a cache entry on purpose: round-robin fairness must
 * survive a cache flush, and the row is the thing an operator can inspect
 * when they ask "why did Ada get three in a row?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_score_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('event', 32);
            $table->integer('points')->default(0);
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
        });

        Schema::create('crm_round_robin_state', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // contact | deal | task
            $table->string('scope', 16);
            $table->unsignedBigInteger('cursor')->default(0);

            $table->timestamps();

            $table->unique(['customer_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_round_robin_state');
        Schema::dropIfExists('crm_lead_score_rules');
    }
};
