<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — outgoing webhook endpoints (spec §2.14.2 F-2.14.2-001/002/005).
 *
 * One row per registered endpoint. `secret` is the HMAC-SHA-256 key used to
 * sign every delivery body (X-EMP-Signature) and is encrypted at rest, the
 * same convention as delivery_servers.password (D-48). `events` is the
 * subscription checkbox set — an empty array means "no events", never "all",
 * so a half-configured endpoint is silent rather than noisy.
 *
 * `consecutive_failures` / `last_delivery_at` are observability only; a
 * webhook is disabled exclusively by the customer (F-2.14.2-005) — the
 * platform never auto-disables an endpoint behind their back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('secret');
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
