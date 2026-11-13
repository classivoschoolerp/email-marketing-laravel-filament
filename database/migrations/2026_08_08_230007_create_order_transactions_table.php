<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.2 order transaction log (WP-23): every gateway interaction —
 * payment intents, webhooks, captures, proof uploads, manual status
 * changes, refunds — with the gateway reference + raw payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_key', 64)->nullable();
            $table->string('type', 32);
            $table->string('status', 32)->nullable();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transactions');
    }
};
