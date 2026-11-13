<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 — trackable URLs per campaign (spec §0.5 / §1.7 F-1.7-002/008).
 * WP-13's content rewriter inserts one row per distinct URL in a campaign;
 * the public click endpoint looks the row up by (campaign_uid, hash).
 *
 * `hash` is an HMAC-SHA256 of the destination URL keyed with the app key
 * (App\Services\Tracking\UrlSigner) so tracked links cannot be forged or
 * tampered with (F-1.7-008).
 *
 * `campaign_id` is a plain unsignedBigInteger (nullable, indexed) — NOT a
 * foreign key: the campaigns table is owned by WP-11 and does not exist yet.
 * WP-11 backfills/uses it; `campaign_uid` is the stable public identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_track_urls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('campaign_uid', 36);
            $table->text('url');
            $table->string('hash', 64);
            $table->timestamps();

            $table->unique(['campaign_uid', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_track_urls');
    }
};
