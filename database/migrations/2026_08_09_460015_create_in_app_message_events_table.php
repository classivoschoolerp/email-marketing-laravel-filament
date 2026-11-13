<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — in-app message impressions, clicks and dismissals
 * (spec §2.10 F-2.10-011).
 *
 * One row per event, keyed by the hashed `visitor_key` from the §1.8 landing
 * tracking convention. This table is what makes the frequency caps REAL: a
 * `once` message is one whose (message, visitor) impression row already
 * exists, and `max_impressions` is a COUNT. Storing "seen" in localStorage
 * instead — which is what a client-only implementation would do — means the
 * cap is advisory and a cleared browser replays every message.
 *
 * The unique (message, visitor, type, scope) index is also the idempotency
 * claim for the beacon: a page that fires it twice on a double render cannot
 * inflate the count.
 *
 * `scope` is NOT NULL and carries a sentinel rather than a null, deliberately.
 * SQL treats NULLs as DISTINCT in a unique index — the very property sms_logs
 * relies on to let transactional rows escape its campaign claim — so a
 * nullable column here would silently disable the claim for exactly the
 * once-per-visitor-forever case it exists to enforce. `*` means "for all
 * time", anything else is the visitor's hashed session id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_message_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('in_app_message_id');
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->string('visitor_key', 64);
            $table->string('session_key', 64)->default('-');
            $table->string('scope', 64)->default('*'); // '*' = for all time
            $table->string('type', 16); // impression|click|dismiss
            $table->string('url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['in_app_message_id', 'visitor_key', 'type', 'scope'], 'in_app_events_claim_unique');
            $table->index(['in_app_message_id', 'type']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_message_events');
    }
};
