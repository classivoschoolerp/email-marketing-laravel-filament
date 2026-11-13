<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — synced carts / abandoned checkouts + their items
 * (spec §2.12 F-2.12-002/004).
 *
 * A cart is "abandoned" when it has been idle longer than
 * `emp-ecommerce.carts.abandon_after_minutes` and carries no order. The tick
 * stamps `abandoned_at` and then `abandoned_notified_at` — the second stamp is
 * the idempotency mark, so a cart that stays abandoned for a week raises
 * `cart_abandoned` exactly once (F-2.4.2-015).
 *
 * `recovery_url` is the shop's own checkout-recovery link; it is what the cart
 * contents block renders as its button, because only the shop can restore a
 * session.
 *
 * Money in integer MINOR UNITS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained('store_connections')->cascadeOnDelete();
            $table->foreignId('store_customer_id')->nullable()->constrained('store_customers')->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->foreignId('store_order_id')->nullable()->constrained('store_orders')->nullOnDelete();

            $table->string('external_id', 191);
            $table->string('email')->nullable();
            $table->char('currency', 3)->default('USD');

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedInteger('item_count')->default(0);

            $table->string('recovery_url', 2048)->nullable();
            $table->string('status', 16)->default('open'); // open|abandoned|converted

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('abandoned_notified_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_connection_id', 'external_id'], 'store_carts_external_unique');
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'last_activity_at'], 'store_carts_abandon_idx');
            $table->index(['customer_id', 'email']);
        });

        Schema::create('store_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_cart_id')->constrained('store_carts')->cascadeOnDelete();
            $table->foreignId('store_product_id')->nullable()->constrained('store_products')->nullOnDelete();

            $table->string('external_id', 191)->nullable();
            $table->string('product_external_id', 191)->nullable();
            $table->string('title');
            $table->string('sku', 191)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->timestamps();

            $table->index(['store_cart_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_cart_items');
        Schema::dropIfExists('store_carts');
    }
};
