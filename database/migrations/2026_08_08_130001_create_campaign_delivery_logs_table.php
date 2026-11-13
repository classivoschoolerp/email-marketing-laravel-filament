<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-13 — per-recipient delivery log (spec §0.6 / §4.6). One row per
 * (campaign, subscriber); the unique constraint is the second idempotency
 * arbiter after the IdempotentJob cache lock (F-0.4-004 / F-4.6-002): even
 * if two workers race past the lock, only one row — and one send — wins.
 *
 * status: sent | failed | retrying | skipped (skipped = suppressed at send
 * time: spam-block, subscriber no longer active, campaign no longer sending).
 * `retries` counts driver attempts made by RetryPolicy. `response` stores the
 * driver message or error text; `message_id` the provider message id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_delivery_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('subscriber_id');
            $table->unsignedBigInteger('delivery_server_id')->nullable()->index();
            $table->string('status', 16)->index();
            $table->string('message_id')->nullable();
            $table->text('response')->nullable();
            $table->unsignedSmallInteger('retries')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'subscriber_id']);
            $table->index(['campaign_id', 'status']);
            $table->index('subscriber_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_delivery_logs');
    }
};
