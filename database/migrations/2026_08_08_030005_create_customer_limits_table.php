<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer quota overrides (spec §3.4 F-3.4-008): same keys as
 * customer_group_limits; QuotaService checks these BEFORE the group limit.
 * value null = unlimited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->integer('value')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_limits');
    }
};
