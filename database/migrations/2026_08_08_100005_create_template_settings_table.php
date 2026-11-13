<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-level key-value settings for the templates module (WP-10). Kept
 * separate from app/Settings (WP-04 territory). First key: "default_builder"
 * (spec §2.5.3 F-2.5.3-005 — admin chooses the platform default builder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_settings');
    }
};
