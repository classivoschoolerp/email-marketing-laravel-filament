<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.1 price plans (WP-23).
 * - base_price: integer minor units of currency_code (docs/ARCHITECTURE.md — no floats).
 * - prices: json map ISO code => minor-unit int, the per-plan multi-currency
 *   overrides (F-3.26.1-012). A plan is purchasable only in its base currency
 *   and the currencies listed here (no silent exchange-rate conversion).
 * - customer_group_id: the linked group that defines limits & permissions
 *   (F-3.26.1-002); nullable for credit packs sold on top of a base plan.
 * - limits_override: json map limit key => value applied as customer_limits
 *   rows on subscribe (F-3.26.1-006/015, D-43 override chain).
 * - credit packs (F-3.26.1-013, D-73): is_credit_pack + credits + credit_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_model', 32)->default('monthly');
            $table->unsignedBigInteger('base_price')->default(0);
            $table->string('currency_code', 3);
            $table->json('prices')->nullable();
            $table->boolean('trial_enabled')->default(false);
            $table->unsignedInteger('trial_days')->default(0);
            $table->boolean('trial_once_per_customer')->default(true);
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('recommended')->default(false);
            $table->json('features')->nullable();
            $table->json('limits_override')->nullable();
            $table->boolean('recurring')->default(true);
            $table->boolean('is_credit_pack')->default(false);
            $table->unsignedBigInteger('credits')->default(0);
            $table->string('credit_type', 16)->nullable();
            $table->timestamps();

            $table->index(['visible', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_plans');
    }
};
