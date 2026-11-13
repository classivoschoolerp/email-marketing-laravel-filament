<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — synced products (spec §2.12 F-2.12-002/003/005).
 *
 * Backs the in-builder catalogue browser (F-2.12-003) and the recommendation
 * blocks (F-2.12-005). `units_sold` is maintained from synced order items, so
 * "best sellers" is a real figure from the tenant's own orders rather than a
 * vendor popularity guess we cannot verify.
 *
 * `price` is integer MINOR UNITS. `in_stock` + `back_in_stock_at` drive the
 * back-in-stock trigger (F-2.4.2-015): the sync stamps the transition moment,
 * which is what makes the trigger fire once rather than on every re-read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained('store_connections')->cascadeOnDelete();

            $table->string('external_id', 191);
            $table->string('title');
            $table->string('sku', 191)->nullable();
            $table->text('description')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('category', 191)->nullable();
            $table->json('categories')->nullable();

            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->char('currency', 3)->nullable();

            $table->boolean('in_stock')->default(true);
            $table->integer('inventory')->nullable();
            $table->unsignedInteger('units_sold')->default(0);

            $table->timestamp('back_in_stock_at')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['store_connection_id', 'external_id'], 'store_products_external_unique');
            $table->index(['customer_id', 'in_stock']);
            $table->index(['customer_id', 'category']);
            $table->index(['store_connection_id', 'units_sold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }
};
