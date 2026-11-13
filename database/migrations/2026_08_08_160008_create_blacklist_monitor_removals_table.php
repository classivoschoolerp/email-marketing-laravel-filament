<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — §3.18 removal log (F-3.18-003): every blacklist row a monitor
 * deleted, with the entry's original reason/source preserved so a removal
 * can be audited after the blacklist row is gone. monitor_id is nullOnDelete
 * so the log outlives the monitor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_monitor_removals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blacklist_monitor_id')->nullable()->constrained('blacklist_monitors')->nullOnDelete();
            $table->string('email');
            $table->string('reason')->nullable();
            $table->string('source', 32)->nullable();

            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_monitor_removals');
    }
};
