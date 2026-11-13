<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-24: the §6.6 F-6.6-004 index health check found a genuinely missing
 * index — `campaign_delivery_logs.message_id`.
 *
 * D-65 makes that column the primary bounce-attribution join key ("original
 * Message-ID → delivery_logs.message_id"), so every DSN processed by
 * `bounces:process` was doing a full scan of a partitioned, high-volume table.
 * Adding the index is additive and cannot change any existing behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_delivery_logs')) {
            return;
        }

        Schema::table('campaign_delivery_logs', function (Blueprint $table): void {
            $table->index('message_id', 'campaign_delivery_logs_message_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('campaign_delivery_logs')) {
            return;
        }

        Schema::table('campaign_delivery_logs', function (Blueprint $table): void {
            $table->dropIndex('campaign_delivery_logs_message_id_index');
        });
    }
};
