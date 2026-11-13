<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-07 — dynamic segments (spec §2.2.10). Segments store conditions only;
 * membership is evaluated at read time by SegmentQueryBuilder (F-2.2.10-012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_segments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type', 3)->default('all'); // all|any
            $table->timestamps();

            $table->index(['list_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_segments');
    }
};
