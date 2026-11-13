<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-plugin configuration (spec §6.1 F-6.1-005). Values are written by the
 * schema-driven settings form; `is_secret` rows are stored encrypted so a
 * plugin's API keys never sit in plain text (same posture as D-48 driver
 * secrets).
 *
 * Rows survive `disable` and are only dropped by an uninstall where the
 * operator answered "delete data" to the retention prompt (F-6.1-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();
            $table->string('key', 128);
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();

            $table->unique(['plugin_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_settings');
    }
};
