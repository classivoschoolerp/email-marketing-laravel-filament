<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — outbound webhook call log (F-2.4.2-030 action node + F-2.4.3-006
 * error log). One row per webhook node execution; AutomationWebhookJob
 * records HTTP-level retry attempts, the final status code and a truncated
 * response body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('automation_nodes')->nullOnDelete();
            $table->string('url', 2048);
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('pending'); // pending|success|failed
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_webhook_logs');
    }
};
