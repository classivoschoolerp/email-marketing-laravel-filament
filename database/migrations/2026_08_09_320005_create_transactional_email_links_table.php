<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — per-message tracked link table for transactional click tracking
 * (spec §2.11 F-2.11-004/007).
 *
 * campaign_track_urls cannot be reused: it is keyed by campaign_uid and a
 * transactional message belongs to no campaign. The tamper-proofing is
 * identical though — `hash` is the UrlSigner HMAC of the destination
 * (F-1.7-008), so a forged or edited hash matches no row and 404s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactional_email_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('log_id')->constrained('transactional_email_logs')->cascadeOnDelete();
            $table->string('hash', 64);
            $table->string('url', 2048);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamps();

            $table->unique(['log_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_email_links');
    }
};
