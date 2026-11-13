<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP/domain warmup plans (spec §2.9.3, WP-33). customer_id NULL = system row
 * usable by admin-owned delivery servers (same convention as
 * delivery_servers / bounce_servers).
 *
 * The ramp is fully described by (days, start_volume, increment_type,
 * increment_value, strategy, max_daily_volume): WarmupScheduleBuilder turns
 * it into one warmup_plan_schedules row per day (F-2.9.3-001/002/007). The
 * schedule table — not this row — is the authority for a day's allowance once
 * generated, because operators may hand-edit any day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // F-2.9.3-001 — ramp definition
            $table->unsignedSmallInteger('days')->default(30);
            $table->unsignedInteger('start_volume')->default(50);
            $table->string('increment_type', 16)->default('fixed');   // fixed | percent
            $table->unsignedInteger('increment_value')->default(50);
            $table->unsignedInteger('max_daily_volume')->default(0);  // 0 = uncapped (D-23)

            // F-2.9.3-007 — linear | exponential growth curve
            $table->string('strategy', 16)->default('linear');

            // draft | active | paused | completed (F-2.9.3-006)
            $table->string('status', 16)->default('draft');

            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_plans');
    }
};
