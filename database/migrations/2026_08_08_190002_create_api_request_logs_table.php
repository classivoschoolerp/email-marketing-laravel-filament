<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-19 — API request log (spec §3.25.7 request logging + §2.14.1 per-key log).
 *
 * Written by the ApiRequestLogger middleware when ApiSettings::request_logging_enabled
 * is on. Rows past the retention window are pruned opportunistically by the same
 * middleware (lottery-based — see PruneApiRequestLogs); no new cron name is added.
 * `api_key_id` is nullable so failed-auth requests are auditable too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['api_key_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
