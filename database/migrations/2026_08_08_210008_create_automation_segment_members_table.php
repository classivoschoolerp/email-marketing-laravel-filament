<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — membership snapshot for "contact enters/exits a segment" triggers
 * (F-2.4.2-018). Segments are dynamic (F-2.2.10-012), so the tick diffs the
 * segment's current matches against this snapshot: new rows = entered,
 * missing rows = exited. Seeded (without enrolling) when the automation is
 * published/activated so pre-existing members never flood the journey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_segment_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['automation_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_segment_members');
    }
};
