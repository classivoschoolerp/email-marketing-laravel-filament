<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — synced shop customers (spec §2.12 F-2.12-002).
 *
 * `unique(store_connection_id, external_id)` is what makes the sync
 * IDEMPOTENT: re-reading a page upserts, it never duplicates. The same index
 * is the lookup the order/cart writers use to resolve a shopper.
 *
 * `total_spent` is integer MINOR UNITS (docs/ARCHITECTURE.md — money is never a float);
 * it mirrors the shop's own lifetime figure, while the platform's own
 * purchase segmentation sums `store_orders` so it can window by date.
 *
 * `subscriber_id` links a shopper to a list member when the email matches one
 * — nullable, because a shop customer who never subscribed is still worth
 * syncing (their orders still attribute revenue to whoever mailed them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained('store_connections')->cascadeOnDelete();

            $table->string('external_id', 191);
            $table->string('email')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('country', 64)->nullable();
            $table->string('city', 64)->nullable();

            $table->unsignedBigInteger('total_spent')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->char('currency', 3)->nullable();
            $table->boolean('accepts_marketing')->default(false);

            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();

            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_connection_id', 'external_id'], 'store_customers_external_unique');
            $table->index(['customer_id', 'email']);
            $table->index(['customer_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_customers');
    }
};
