<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2.13 F-2.13-003 — deals, multiple pipelines, custom stages and the
 * drag-and-drop board's persisted ordering (WP-44).
 *
 * `crm_deals.position` is what makes the board real: the browser reports the
 * new order of a column after a drop and the server rewrites the integers
 * (App\Services\Crm\PipelineBoard::move()). Nothing about the board lives in
 * browser state — reload the page and the columns are exactly as they were.
 *
 * Money is integer minor units (docs/ARCHITECTURE.md), never a float: `value_minor` +
 * `currency`.
 *
 * `crm_deal_stage_events` is the stage-change history. It stores the stage
 * NAMES alongside the ids so a renamed or deleted stage still narrates its
 * own history, the same reasoning SubscriberActivityTimeline uses for
 * soft-deleted campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->string('currency', 3)->default('USD');

            $table->timestamps();

            $table->index(['customer_id', 'position']);
        });

        Schema::create('crm_pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            // Forecast weight, 0..100.
            $table->unsignedTinyInteger('probability')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);

            $table->timestamps();

            $table->index(['pipeline_id', 'position']);
        });

        Schema::create('crm_deals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->foreignId('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('crm_pipeline_stages')->cascadeOnDelete();

            $table->foreignId('contact_id')->nullable()
                ->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->constrained('crm_companies')->nullOnDelete();
            $table->foreignId('owner_sub_user_id')->nullable()
                ->constrained('sub_users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Integer minor units (docs/ARCHITECTURE.md "Money as integer minor units").
            $table->bigInteger('value_minor')->default(0);
            $table->string('currency', 3)->default('USD');

            // open | won | lost
            $table->string('status', 8)->default('open');
            $table->date('expected_close_on')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Board ordering inside (pipeline, stage). Server-owned.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'pipeline_id', 'stage_id', 'position'], 'crm_deals_board_index');
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'contact_id']);
        });

        Schema::create('crm_deal_stage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained('crm_deals')->cascadeOnDelete();

            $table->unsignedBigInteger('from_stage_id')->nullable();
            $table->unsignedBigInteger('to_stage_id');
            $table->string('from_stage_name')->nullable();
            $table->string('to_stage_name');
            $table->string('actor_name')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'deal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deal_stage_events');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_pipelines');
    }
};
