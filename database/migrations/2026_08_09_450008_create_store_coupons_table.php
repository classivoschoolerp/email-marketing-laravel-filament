<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — generated coupon codes (spec §2.12 F-2.12-008).
 *
 * A batch is a `store_coupon_batches` row; every code it mints is a
 * `store_coupons` row. `unique(customer_id, code)` guarantees the generator
 * can never hand the same code to two recipients — the DB is the arbiter, not
 * the loop that generated it.
 *
 * `assigned_subscriber_id` is what makes {COUPON_CODE} a PER-RECIPIENT merge
 * tag: the send path claims the next unassigned code of the batch inside a
 * locked transaction, so two workers mailing the same campaign never issue one
 * code twice.
 *
 * `discount_value` is either basis points of a percentage (percent type,
 * 1000 = 10.00%) or integer MINOR UNITS (fixed type) — both integers, never
 * a float.
 *
 * `pushed_at` / `external_id` record that the code was created in the SHOP
 * too, when the connector supports it. A connector that cannot create
 * discounts leaves them null and the UI says the code must be loaded into the
 * shop by hand — inventing a redemption we cannot honour would be worse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_coupon_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->nullable()->constrained('store_connections')->nullOnDelete();

            $table->string('name');
            $table->string('prefix', 24)->nullable();
            $table->string('discount_type', 16)->default('percent'); // percent|fixed|free_shipping
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('minimum_order')->nullable();

            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('usage_limit')->default(1);
            $table->boolean('single_use')->default(true);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('store_coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_coupon_batch_id')->constrained('store_coupon_batches')->cascadeOnDelete();

            $table->string('code', 64);
            $table->foreignId('assigned_subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('automation_id')->nullable();

            $table->string('external_id', 191)->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->unsignedInteger('redemptions')->default(0);

            $table->timestamps();

            $table->unique(['customer_id', 'code'], 'store_coupons_code_unique');
            $table->index(['store_coupon_batch_id', 'assigned_subscriber_id'], 'store_coupons_batch_assign_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_coupons');
        Schema::dropIfExists('store_coupon_batches');
    }
};
