<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-20 — in-app translation string overrides (spec §6.5 F-6.5-003,
 * §3.22 editor). Stored per (locale, group, key) and merged over the file
 * strings by App\Services\I18n\OverrideTranslationLoader, so upgrades never
 * clobber operator translations and lang/ files stay pristine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 12);
            $table->string('group', 64);
            $table->string('key', 191);
            $table->text('value');
            $table->timestamps();

            $table->unique(['locale', 'group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
    }
};
