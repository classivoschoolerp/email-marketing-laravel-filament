<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign groups (spec §2.3.10 F-2.3.10-001) — simple per-tenant folders
 * campaigns can be assigned to and filtered by. Tags are P2 and excluded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_groups');
    }
};
