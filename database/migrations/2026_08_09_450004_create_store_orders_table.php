<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — synced orders + line items (spec §2.12 F-2.12-002/006/007).
 *
 * EVERY money column is integer MINOR UNITS (docs/ARCHITECTURE.md; the §3.26 billing
 * rule applied to shop money). `currency` is stored per order because a shop
 * may sell in several and a segment on "spent over 100" must never add
 * dollars to yen — PurchaseStats sums per currency.
 *
 * `unique(store_connection_id, external_id)` makes the sync and the webhook
 * converge on the same row: a webhook that arrives before the nightly sync
 * simply wins, and the sync then updates rather than duplicating.
 *
 * The two lifecycle stamps are the ecommerce trigger producers (F-2.4.2-015):
 * `placed_at` → order_placed, `shipped_at` → order_shipped. `*_notified_at`
 * are the idempotency marks — an order re-read a hundred times raises each
 * event exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->nullable()->constrained('store_customers')->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();

            $table->string('external_id', 191);
            $table->string('number', 64)->nullable();
            $table->string('email')->nullable();
            $table->char('currency', 3)->default('USD');

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('shipping_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->string('status', 32)->default('pending');       // pending|paid|refunded|cancelled
            $table->string('financial_status', 32)->nullable();
            $table->string('fulfillment_status', 32)->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->string('order_url', 2048)->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamp('placed_notified_at')->nullable();
            $table->timestamp('shipped_notified_at')->nullable();
            $table->timestamp('attributed_at')->nullable();

            $table->timestamp('external_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_connection_id', 'external_id'], 'store_orders_external_unique');
            $table->index(['customer_id', 'placed_at']);
            $table->index(['customer_id', 'email']);
            $table->index(['customer_id', 'subscriber_id']);
            $table->index(['store_connection_id', 'status']);
        });

        Schema::create('store_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->foreignId('store_product_id')->nullable()->constrained('store_products')->nullOnDelete();

            $table->string('external_id', 191)->nullable();
            $table->string('product_external_id', 191)->nullable();
            $table->string('title');
            $table->string('sku', 191)->nullable();
            $table->string('category', 191)->nullable();
            $table->string('image_url', 2048)->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->timestamps();

            $table->index(['store_order_id']);
            $table->index(['customer_id', 'category']);
            $table->index(['customer_id', 'store_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_orders');
    }
};
