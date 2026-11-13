<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded theme packages (spec §6.3 F-6.3-005, §3.21 F-3.21-002).
 *
 * Install-wide operator data — deliberately NOT tenant-scoped: a theme is a
 * skin for the whole installation (or for one brand domain), never something
 * a customer uploads.
 *
 * The BUILT-IN themes (default + the seven named palettes) are code, not
 * rows: they live in App\Services\Themes\ThemePalettes so a fresh install has
 * a full theme list before anything is uploaded and so they can never be
 * deleted by accident. Only uploaded packages appear here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            // Directory name under config('emp-themes.path'). Validated
            // against ThemeManifestValidator::SLUG_PATTERN before any write.
            $table->string('slug', 64)->unique();

            $table->string('name');
            $table->string('version', 32)->default('1.0.0');
            $table->string('author')->nullable();
            $table->text('description')->nullable();
            $table->string('homepage', 2048)->nullable();

            // Stylesheet path RELATIVE to the package directory, from the
            // manifest. Never taken from a request.
            $table->string('stylesheet', 255)->default('theme.css');

            // Areas the package declares support for: backend|customer|frontend.
            $table->json('areas')->nullable();

            // Colour tokens the manifest declares; merged over the base
            // palette so a package need only override what it changes.
            $table->json('palette')->nullable();

            // sha256 of the uploaded archive — audit trail for "which build
            // is actually on this server".
            $table->string('checksum', 64)->nullable();

            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
