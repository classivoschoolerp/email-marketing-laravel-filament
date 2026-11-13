<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — one row per person taking a survey (spec F-2.8-005, F-1.9-002).
 *
 * `uid` is the resume key for the multi-step public flow: no session is
 * needed, the URL carries an unguessable uuid (same access model as public
 * list pages). `subscriber_id` NULL = anonymous response (F-2.8-009).
 *
 * Only `status = complete` rows count against the survey's max_responders
 * (F-1.9-004) and only completions raise `survey_completed` on the automation
 * bus (F-2.4.2-012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()
                ->constrained('subscribers')->nullOnDelete();

            $table->string('email', 191)->nullable();
            // incomplete|complete
            $table->string('status', 16)->default('incomplete');
            $table->unsignedSmallInteger('current_step')->default(1);

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'status']);
            $table->index(['survey_id', 'subscriber_id']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responders');
    }
};
