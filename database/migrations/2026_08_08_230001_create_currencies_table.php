<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.5 currencies (WP-23). exchange_rate is the multiplier from the
 * default currency to this one (default row = 1). It is a decimal COLUMN but
 * all arithmetic on it is done in integer math (see CurrencyConverter) —
 * money itself is always integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 3)->unique();
            $table->string('symbol', 16);
            $table->string('placement', 16)->default('before');
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
