<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — browser push subscriptions (spec §2.10 F-2.10-010).
 *
 * One row per (tenant, browser). The three fields the protocol needs come
 * straight from `PushSubscription.toJSON()`:
 *
 *   endpoint  the URL to POST to — the push service chose it, and it is long
 *   p256dh    the browser's public key (base64url of 65 raw octets)
 *   auth      the browser's auth secret (base64url of 16 raw octets)
 *
 * `endpoint` is unique PER TENANT rather than globally: the same browser can
 * legitimately subscribe to two different customers' sites, and a global
 * unique index would let whichever tenant registered first block the other.
 * The index is on a hash rather than the endpoint itself — endpoints run to
 * hundreds of characters and exceed MySQL's index key length (D-03 keeps
 * migrations MySQL-compatible).
 *
 * `subscriber_id` is nullable on purpose: a visitor can allow notifications
 * long before they ever identify themselves, and those subscriptions are
 * still sendable — they simply cannot be segment-targeted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64);
            $table->string('p256dh', 200);
            $table->string('auth', 64);
            $table->string('user_agent', 500)->nullable();
            $table->string('locale', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'endpoint_hash']);
            $table->index(['customer_id', 'is_active']);
            $table->index('subscriber_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};
