<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI usage ledger (spec §2.15.8 F-2.15.8-010, WP-37).
 *
 * One row per completion attempt — successful or not. This is the ledger the
 * credit meter is reconciled against: `credits` is what was actually charged
 * (0 on failure, because a failed call is refunded), while prompt/completion
 * tokens record what the vendor reported.
 *
 * customer_id is nullable on purpose: the admin-side chatbox runs as the
 * OPERATOR, has no tenant and consumes no tenant credits, but its usage must
 * still be auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();

            // Admin user that triggered it, when the actor was an operator.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Which AI capability: subject_lines, body_copy, translate, chat…
            $table->string('feature', 64);

            // Registry key + the model the engine actually used.
            $table->string('engine', 64);
            $table->string('model', 190)->nullable();

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            // Credits charged against customer_limits.ai_credits.
            $table->unsignedInteger('credits')->default(0);

            $table->boolean('ok')->default(true);
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
