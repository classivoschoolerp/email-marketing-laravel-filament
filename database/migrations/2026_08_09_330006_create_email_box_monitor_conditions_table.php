<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitor conditions (spec §2.9.6 F-2.9.6-002/003/004): subject / body / from
 * matched by contains, not-contains or a PCRE pattern, each carrying its own
 * action. A monitor may hold many conditions; `priority` (ascending, then id)
 * decides which one wins — the FIRST match applies its action and processing
 * of that message stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_box_monitor_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_box_monitor_id')->constrained('email_box_monitors')->cascadeOnDelete();

            $table->string('field', 16);      // subject | body | from
            $table->string('operator', 16);   // contains | not_contains | regex
            $table->string('value', 512);

            $table->string('action', 32);     // unsubscribe | blacklist | delete_subscriber | move_to_list | notify_admin
            $table->foreignId('target_list_id')->nullable()->constrained('lists')->nullOnDelete();
            $table->string('notify_email')->nullable();

            $table->unsignedSmallInteger('priority')->default(10);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['email_box_monitor_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_box_monitor_conditions');
    }
};
