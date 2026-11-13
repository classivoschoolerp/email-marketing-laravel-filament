<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Postmaster Tools daily traffic stats (spec §2.9.9 F-2.9.9-005, WP-46).
 *
 * One row per (domain, stat_date) — the natural key of the API's
 * `domains/{domain}/trafficStats/{YYYYMMDD}` resource.
 *
 * EVERY metric column is NULLABLE and stays NULL when Google omits the field.
 * Google omits fields for a date with too little traffic to report, and a 0.0
 * there would read as "a perfect day" on a chart. The read-out shows a gap
 * instead (the sendTimeAnalysis() precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postmaster_daily_stats', function (Blueprint $table): void {
            $table->id();

            // Mirrors the owning sending domain so the customer read-out can
            // be tenant-scoped. NULL = a global/admin domain (§3.13.5).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sending_domain_id')->nullable()->constrained()->nullOnDelete();

            $table->string('domain', 255);
            $table->date('stat_date');

            // REPUTATION_CATEGORY enum: BAD | LOW | MEDIUM | HIGH (or unset).
            $table->string('domain_reputation', 32)->nullable();

            // Ratios are 0..1 as Google reports them; null = not reported.
            $table->double('spf_success_ratio')->nullable();
            $table->double('dkim_success_ratio')->nullable();
            $table->double('dmarc_success_ratio')->nullable();
            $table->double('user_reported_spam_ratio')->nullable();
            $table->double('outbound_encryption_ratio')->nullable();
            $table->double('inbound_encryption_ratio')->nullable();

            // Repeated fields, kept as the API shapes them.
            $table->json('ip_reputations')->nullable();
            $table->json('spammy_feedback_loops')->nullable();
            $table->json('delivery_errors')->nullable();

            // The untouched payload, so a future column never needs a re-sync.
            $table->json('payload')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'stat_date']);
            $table->index(['customer_id', 'stat_date']);
            $table->index('stat_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postmaster_daily_stats');
    }
};
