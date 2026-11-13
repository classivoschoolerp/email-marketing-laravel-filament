<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — link shortener with click tracking (spec §2.10 F-2.10-007).
 *
 * SMS has no HTML to rewrite, so tracking is the short code itself: the
 * campaign body's URLs are replaced with /s/{code} before sending and the
 * redirect counts the hit. Codes are per (customer, target, sms_log) so
 * per-recipient click attribution works (F-2.10-006 "clicks on short links")
 * while a campaign-level row aggregates the total.
 *
 * `code` is unique install-wide because the redirect route carries no tenant
 * segment — the code IS the lookup key, like a tracking uid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('target_url', 2048);
            $table->unsignedBigInteger('sms_campaign_id')->nullable();
            $table->unsignedBigInteger('sms_log_id')->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'sms_campaign_id']);
            $table->index('sms_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
