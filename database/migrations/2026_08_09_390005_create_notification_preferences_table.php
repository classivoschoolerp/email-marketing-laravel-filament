<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2.15.3 notification preferences (WP-39): one row per
 * customer × event × channel, storing ONLY the deviations from the
 * NotificationEvent's default. A missing row means "use the default", so a
 * new event added later is on for everyone without a backfill.
 *
 * `sub_user_id` is nullable: null = the account-wide preference set by the
 * owner; set = that team member's personal override for their own inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_user_id')->nullable()->constrained()->cascadeOnDelete();

            // NotificationEvent value, e.g. 'campaign_finished'.
            $table->string('event', 48);
            // NotificationChannel value: 'in_app' | 'email'.
            $table->string('channel', 16);

            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'sub_user_id', 'event', 'channel'], 'notification_preferences_unique');
            $table->index(['customer_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
