<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-40 — scheduled analytics report emails (spec §4.2 F-4.2-011).
 *
 * `next_run_at` is the only thing the runner scans, so the hourly command is
 * a single indexed query no matter how many tenants exist. It is recomputed
 * AFTER a successful send, which means a worker outage delays a report but
 * never silently skips one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name');
            $table->string('frequency', 16)->default('weekly'); // weekly|monthly
            $table->string('range_key', 16)->default('30d');    // AnalyticsRange preset
            $table->json('recipients')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedTinyInteger('send_hour')->default(7);
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_run_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'next_run_at'], 'scheduled_reports_due_idx');
            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
