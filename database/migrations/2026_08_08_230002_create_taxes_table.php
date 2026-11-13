<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.6 tax rules (WP-23). percentage_bp = percentage in basis points
 * (2000 = 20.00%) so tax math stays exact integer arithmetic. country is
 * ISO-3166 alpha-2; null = matches any. zone matches the customer's
 * state/zone free-text; postcode_pattern is a regex applied to the
 * customer's postcode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('percentage_bp');
            $table->string('country', 2)->nullable();
            $table->string('zone')->nullable();
            $table->string('postcode_pattern')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();

            $table->index(['active', 'country']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
