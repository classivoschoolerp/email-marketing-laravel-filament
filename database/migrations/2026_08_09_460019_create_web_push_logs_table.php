<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — web push delivery log (spec §2.10 F-2.10-010).
 *
 * The unique (web_push_campaign_id, web_push_subscription_id) pair is the
 * idempotency arbiter for campaign fan-out, exactly as on sms_logs and
 * whatsapp_logs: a second worker racing the same subscription loses the insert
 * and stops (docs/ARCHITECTURE.md rule 6). Transactional and automation rows carry NULL
 * campaign ids, which SQL treats as distinct, so they are never blocked.
 *
 * There is no `delivered` status and there never can be one: the push protocol
 * gives the sender an ACCEPTED receipt from the push service and nothing more.
 * Whether the browser ever rendered the notification is not observable, so the
 * only evidence of real delivery is a CLICK, which the service worker reports
 * back. Inventing a delivered count here would be inventing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('web_push_campaign_id')->nullable();
            $table->unsignedBigInteger('web_push_subscription_id')->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->string('uid', 36)->unique();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 2048)->nullable();
            // TTL and urgency are stored PER MESSAGE, not read back from the
            // campaign at delivery time: a transactional or automation send has
            // no campaign, and silently substituting the defaults would mean a
            // caller's explicit "hold this for 10 minutes" was ignored.
            $table->unsignedInteger('ttl')->default(2419200);
            $table->string('urgency', 16)->default('normal');
            $table->string('status', 16)->index(); // queued|sent|failed|expired
            $table->string('source', 16)->default('campaign'); // campaign|transactional|automation
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->unique(['web_push_campaign_id', 'web_push_subscription_id'], 'web_push_logs_claim_unique');
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_logs');
    }
};
