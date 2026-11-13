<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily send rollups per delivery server (WP-14). Powers the daily/monthly
 * quota checks in ServerPicker (F-2.9.2-029) and the §2.9.1 usage columns.
 * Hourly counting stays in HourlyWindowLimiter (cache), so this table only
 * needs one row per server per day. Deliberately has NO campaign reference —
 * campaigns are a parallel work package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_server_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_server_id')->constrained('delivery_servers')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamps();

            $table->unique(['delivery_server_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_server_usage');
    }
};
