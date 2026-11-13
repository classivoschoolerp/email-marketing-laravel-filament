<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Send Time Optimization toggle (spec §2.3.4 F-2.3.4-011, WP-46).
 *
 * Deliberately a SECOND column beside `send_at_recipient_local_time`
 * (F-2.3.4-010) rather than a shared enum: the two settings answer different
 * questions and a customer may have the local-time shift on already. Which one
 * wins when both are on is decided per RECIPIENT and documented in
 * App\Sending\Pipeline\SendTimeOptimizer — the optimizer's learned hour wins
 * for anyone it has a prediction for, and the timezone shift still applies to
 * everyone it does not, so turning the optimizer on never silently discards
 * the other setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('send_time_optimized')->default(false)->after('send_at_recipient_local_time');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('send_time_optimized');
        });
    }
};
