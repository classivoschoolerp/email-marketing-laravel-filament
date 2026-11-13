<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — WhatsApp delivery log (spec §2.10 F-2.10-009).
 *
 * One row per message. The unique (whatsapp_campaign_id, subscriber_id) pair
 * is the idempotency arbiter for campaign fan-out, exactly as it is on
 * sms_logs: a second worker racing the same recipient loses the insert and
 * stops (docs/ARCHITECTURE.md rule 6). Transactional and automation rows carry NULLs on
 * both columns, which SQL treats as distinct, so they are never blocked.
 *
 * `status` walks queued → sent → delivered → read, or → failed. WhatsApp is
 * the one channel with a real READ receipt, so it gets its own column and
 * counter rather than being folded into `delivered`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('whatsapp_campaign_id')->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->unsignedBigInteger('whatsapp_gateway_id')->nullable();
            $table->unsignedBigInteger('whatsapp_template_id')->nullable();
            $table->string('uid', 36)->unique();
            $table->string('to_number', 32);
            $table->string('template_name')->nullable();
            $table->string('language', 16)->nullable();
            $table->text('body')->nullable(); // rendered preview of what the recipient sees
            $table->json('variables')->nullable();
            $table->string('header_type', 16)->default('none');
            $table->string('header_media_url', 2048)->nullable();
            $table->string('status', 16)->index(); // queued|sent|delivered|read|failed
            $table->string('source', 16)->default('campaign'); // campaign|transactional|automation
            $table->string('driver', 32)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_campaign_id', 'subscriber_id']);
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_logs');
    }
};
