<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — transactional activity log (spec §2.11 F-2.11-004/005/006/009).
 *
 * Every column the spec row names has a home here: `to_email` + `subject`,
 * `status`, open/click counters and first/last timestamps, bounce and spam
 * (complaint) columns, `delivery_time_ms` (queued → provider-accepted) and
 * the provider `message_id`. The rendered `content_html`/`content_text` are
 * stored so the per-message content view and resend (F-2.11-006) reproduce
 * exactly what the recipient got, and `variables` keeps the merge payload so
 * a resend can re-render if the template later changed.
 *
 * `uid` is the public identifier used by `GET /transactional-emails/{id}`
 * (F-Endpoints-to-impleme-017) and by the open/click tracking routes — a
 * uuid, never the autoincrement id, so ids are not enumerable across tenants.
 *
 * Retention (F-2.11-005) prunes on created_at; the index makes that a range
 * delete rather than a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactional_email_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('transactional_email_id')->nullable()->constrained('transactional_emails')->nullOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('subject');
            $table->string('status', 16)->index(); // queued|sent|failed|deferred|bounced|complained
            $table->string('message_id')->nullable();
            $table->unsignedBigInteger('delivery_server_id')->nullable();
            $table->text('response')->nullable();
            $table->longText('content_html')->nullable();
            $table->longText('content_text')->nullable();
            $table->json('variables')->nullable();
            $table->unsignedInteger('opens_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('bounce_type', 16)->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('deferred_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('delivery_time_ms')->nullable();
            $table->unsignedSmallInteger('retries')->default(0);
            $table->unsignedBigInteger('resend_of_id')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index('to_email');
            $table->index('message_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_email_logs');
    }
};
