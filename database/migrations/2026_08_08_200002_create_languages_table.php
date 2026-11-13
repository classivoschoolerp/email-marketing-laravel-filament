<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-20 — installed language packs (spec §3.22 F-3.22-001, §6.5). The
 * default-language source of truth stays CommonSettings::default_language
 * (§3.25.1); is_default here is a mirror kept in sync by
 * App\Services\I18n\Locales::syncDefaultFlag().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name', 64);
            // RTL scripts flip the public layout + panels (F-3.22-003 / F-6.5-006).
            $table->boolean('rtl')->default(false);
            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
