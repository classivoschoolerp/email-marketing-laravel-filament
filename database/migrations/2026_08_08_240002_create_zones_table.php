<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §0.5 / §3.23 F-3.23-005 zones (states / provinces / subdivisions), WP-24.
 * Also backs /api/v1/zones, which shipped in WP-19 as an honest empty
 * collection until this dataset existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('code', 12);
            $table->string('name');
            $table->string('type', 32)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'code']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
