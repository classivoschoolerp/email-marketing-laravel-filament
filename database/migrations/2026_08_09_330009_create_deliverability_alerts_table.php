<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliverability alerts & recommendations feed (spec §2.9.9 F-2.9.9-006/007,
 * WP-33). Rows are produced by DeliverabilityAlertService from the reputation
 * metrics, the DNSBL checks, the domain-authentication summary and the
 * engagement/sunset advisor.
 *
 * customer_id NULL = install-wide alert (fleet IP listings, system domains).
 * `key` deduplicates an open alert: the same finding updates the existing
 * unresolved row instead of flooding the feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverability_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('key');               // dedupe identity, e.g. bounce_rate / dnsbl:1.2.3.4:zen
            $table->string('type', 32);          // reputation | blacklist | authentication | engagement
            $table->string('severity', 16);      // info | warning | critical
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('recommendation')->nullable();
            $table->json('context')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'resolved_at']);
            $table->index(['key', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverability_alerts');
    }
};
