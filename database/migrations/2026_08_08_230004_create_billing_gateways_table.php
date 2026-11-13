<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.3 payment gateway configuration rows (WP-23, D-13). One row per
 * registered gateway key (offline / stripe / paypal / plugin keys). The
 * code adapter lives in the GatewayRegistry; this row carries the operator
 * config: enabled, display name, sandbox/live mode, encrypted credentials,
 * offline instructions text, per-gateway currency restrictions and the
 * checkout sort order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(false);
            $table->string('mode', 16)->default('sandbox');
            $table->text('credentials')->nullable();
            $table->text('instructions')->nullable();
            $table->json('currency_restrictions')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_gateways');
    }
};
