<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — published canvas snapshots (F-2.4.2-002 versioning). One row per
 * publish; the builder's "versions" action lists them and can restore a
 * snapshot back into the draft canvas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('canvas');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['automation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_versions');
    }
};
