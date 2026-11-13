<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.2 orders (WP-23). All amounts are integer minor units of
 * currency_code. line_items is the invoice line breakdown json
 * [{description, quantity, unit_amount, total}]; tax_lines the per-rate
 * breakdown [{name, percentage_bp, amount}]. number = sequential invoice
 * number (prefix + zero-padded id, set on create).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('number')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->json('line_items')->nullable();
            $table->json('tax_lines')->nullable();
            $table->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway_key', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('proof_path')->nullable();
            $table->string('invoice_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
