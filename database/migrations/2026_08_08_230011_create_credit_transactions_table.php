<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-3.26.1-014 credit consumption ledger (WP-23, partial — see ledger
 * notes): every credit-pack purchase writes a positive entry; consumption
 * paths can write negative entries via BillingCredits::record(). balance_after
 * snapshots the customer_limits balance after the movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('credit_type', 16);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'credit_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
