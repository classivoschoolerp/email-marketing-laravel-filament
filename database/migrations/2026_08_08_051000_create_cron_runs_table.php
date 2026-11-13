<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cron run history (spec §3.25.11, F-3.25.11-003): one row per job execution,
 * written by App\Services\Cron\CronRunRecorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('job')->index();
            $table->string('status')->default('running')->index(); // running | success | failed
            $table->dateTime('started_at')->index();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('processed')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_runs');
    }
};
