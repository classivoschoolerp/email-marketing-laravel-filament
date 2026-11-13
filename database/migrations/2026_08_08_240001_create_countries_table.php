<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §0.5 / §3.23 F-3.23-004 countries table (WP-24). Seeded from the WP-02
 * App\Support\Geo\Countries ISO 3166-1 list via `emp:geo-import`; that class
 * now reads this table and falls back to its static list when the table is
 * empty or missing, so every existing caller keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('code3', 3)->nullable();
            $table->string('numeric_code', 3)->nullable();
            $table->string('name');
            $table->string('phone_prefix', 8)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
