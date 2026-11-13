<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-group quota + feature-permission matrices (spec §0.3 / §0.5:
 * customer_group_limits, customer_group_permissions). Key/value rows keyed to
 * customer_groups; QuotaService supplies config defaults for missing keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_group_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            // null = unlimited
            $table->bigInteger('value')->nullable();
            $table->timestamps();

            $table->unique(['customer_group_id', 'key']);
        });

        Schema::create('customer_group_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $table->string('key', 150);
            $table->boolean('allowed')->default(true);
            $table->timestamps();

            $table->unique(['customer_group_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_permissions');
        Schema::dropIfExists('customer_group_limits');
    }
};
