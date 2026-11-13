<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — SMS delivery log (spec §2.10 F-2.10-006/008).
 *
 * One row per message, campaign-driven or transactional (`source`). Idempotency
 * for campaign sends is the unique (sms_campaign_id, subscriber_id) pair — the
 * same second-arbiter shape as campaign_delivery_logs (F-4.6-002); transactional
 * rows carry NULL campaign+subscriber, which SQL treats as distinct, so they are
 * never blocked by it.
 *
 * `status` walks queued → sent → delivered|failed|undelivered; `delivered_at`
 * is stamped by the gateway's delivery-report webhook (F-2.10-006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('sms_campaign_id')->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->unsignedBigInteger('sms_gateway_id')->nullable();
            $table->string('uid', 36)->unique();
            $table->string('to_number', 32);
            $table->string('sender_id')->nullable();
            $table->text('body');
            $table->string('status', 16)->index(); // queued|sent|delivered|failed|undelivered
            $table->string('source', 16)->default('campaign'); // campaign|transactional|automation
            $table->string('driver', 32)->nullable();
            $table->string('gateway_message_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('segments')->default(1);
            $table->string('encoding', 8)->default('gsm7'); // gsm7|ucs2
            $table->unsignedSmallInteger('credits')->default(1);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['sms_campaign_id', 'subscriber_id']);
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index('gateway_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
