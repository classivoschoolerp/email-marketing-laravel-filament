<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer feature-permission overrides beyond the group matrix
 * (spec §3.4 F-3.4-009): same keys as customer_group_permissions;
 * QuotaService::allows() checks these BEFORE the group row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->boolean('allowed');
            $table->timestamps();

            $table->unique(['customer_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_permissions');
    }
};
