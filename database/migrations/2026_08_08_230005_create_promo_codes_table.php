<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.4 promo codes (WP-23).
 * - value: percent type => basis points (1050 = 10.5%); fixed type => minor
 *   units of the DEFAULT currency (converted to the order currency via
 *   exchange-rate integer math at redemption).
 * - min_order_amount: minor units of the DEFAULT currency (same conversion).
 * - plan_ids / customer_group_ids: json restriction lists; null/empty = any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type', 16)->default('percent');
            $table->unsignedBigInteger('value');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->default(0);
            $table->unsignedInteger('max_uses_per_customer')->default(0);
            $table->json('plan_ids')->nullable();
            $table->json('customer_group_ids')->nullable();
            $table->unsignedBigInteger('min_order_amount')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
