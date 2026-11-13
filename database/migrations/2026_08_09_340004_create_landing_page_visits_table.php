<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-34 — landing-page visit tracking (spec §2.6 F-2.6-008, §1.8 F-1.8-003).
 *
 * One row per page view. Unlike §2.7 form impressions — which are counted in
 * a daily rollup because "unique" is not part of that spec row — a landing
 * page must report UNIQUE VISITORS (F-2.6-008), which a counter cannot
 * reconstruct. `visitor_key` is the sticky first-party cookie value hashed
 * with the app key, so the table answers COUNT(DISTINCT visitor_key) without
 * ever storing the raw cookie.
 *
 * `ip` passes through App\Support\Privacy\IpAnonymizer, so the §3.25.16
 * anonymise-IPs setting governs this capture point exactly like the tracking
 * pixel and the public subscribe pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('landing_pages')->cascadeOnDelete();
            $table->foreignId('landing_page_variant_id')->nullable()
                ->constrained('landing_page_variants')->nullOnDelete();

            // sha256(cookie value + app key) — never the raw cookie.
            $table->string('visitor_key', 64);
            $table->string('source', 64)->default('direct');
            $table->string('referrer', 2048)->nullable();
            $table->string('device', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->boolean('converted')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index(['landing_page_id', 'created_at']);
            $table->index(['landing_page_id', 'visitor_key']);
            $table->index(['landing_page_variant_id', 'converted']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_visits');
    }
};
