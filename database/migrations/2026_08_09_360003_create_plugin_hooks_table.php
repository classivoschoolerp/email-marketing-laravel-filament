<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Declared hook subscriptions (spec §6.1 F-6.1-015, §3.20 F-3.20-003).
 *
 * A plugin lists `hooks` in plugin.json; install materialises one row per
 * entry so the operator can SEE and individually disable what third-party
 * code listens to, instead of it being invisible inside a service provider.
 * PluginBooter binds every enabled row to the mapped Laravel event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_hooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plugin_id')->constrained('plugins')->cascadeOnDelete();

            // Stable hook name from App\Plugins\HookCatalogue, e.g.
            // "campaign.pre_send".
            $table->string('hook', 96)->index();

            // "Fqcn\Listener" or "Fqcn\Listener@method".
            $table->string('listener');

            $table->integer('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['plugin_id', 'hook', 'listener']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_hooks');
    }
};
