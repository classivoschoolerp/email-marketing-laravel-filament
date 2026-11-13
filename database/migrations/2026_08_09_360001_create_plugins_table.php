<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installed plugin registry (spec §6.1 F-6.1-001/003, §3.20 F-3.20-001).
 *
 * Install-wide operator data — deliberately NOT tenant-scoped: a plugin adds
 * code to the whole installation, so it can never belong to a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();

            // Directory name under config('emp-plugins.path') and the stable
            // identifier every other table joins on.
            $table->string('slug', 64)->unique();

            $table->string('name');
            $table->string('version', 32);
            $table->string('author')->nullable();
            $table->string('homepage')->nullable();
            $table->text('description')->nullable();

            // Entry class: a subclass of App\Plugins\PluginServiceProvider.
            $table->string('entry_class');

            // Compatibility window against config('emp.core_version') (D-95).
            $table->string('min_core_version', 32)->nullable();
            $table->string('max_core_version', 32)->nullable();

            // installed | enabled | disabled | failed
            $table->string('status', 16)->default('installed')->index();

            // Why the booter disabled it (fail-closed guard, F-6.1-018).
            $table->text('status_reason')->nullable();

            // Whole validated manifest, kept for the admin detail view and so
            // an operator can diff what changed across an update.
            $table->json('manifest')->nullable();

            // sha256 of the uploaded archive — audit trail for "what did we
            // actually run?" when a plugin misbehaves.
            $table->string('checksum', 64)->nullable();

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
