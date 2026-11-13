<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.6 F-6.6-008 worker/node registry (WP-24): which sending nodes are alive
 * and what each is processing. Rows are written by the queue event listener
 * (JobProcessing/JobProcessed/JobFailed) and by `queue:heartbeat`, and pruned
 * once they go stale — see App\Services\Ops\WorkerRegistry.
 *
 * The natural key is (node, pid): one row per OS worker process.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('node', 191);
            $table->unsignedInteger('pid');
            $table->string('queues', 255)->nullable();
            $table->string('connection', 64)->nullable();
            $table->string('current_job', 255)->nullable();
            $table->string('current_job_id', 64)->nullable();
            $table->unsignedBigInteger('jobs_processed')->default(0);
            $table->unsignedBigInteger('jobs_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['node', 'pid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_nodes');
    }
};
