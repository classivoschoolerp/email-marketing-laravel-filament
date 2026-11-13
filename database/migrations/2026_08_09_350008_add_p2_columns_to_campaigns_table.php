<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 campaign option columns (WP-35), following D-54: typed columns on
 * `campaigns`, json only where the option is genuinely list-valued.
 *
 *  §2.3.6 A/B testing        ab_*            (F-2.3.6-001/002/003/005/006/007/008/011)
 *  §2.3.7 recipient filters  filter_match    (F-2.3.7-003)
 *  §2.3.4 send options       send_at_recipient_local_time (F-2.3.4-010),
 *                            frequency_cap_days (F-2.3.4-014),
 *                            webhook_* (F-2.3.4-022)
 *  §2.3.9 lifecycle          archived_at (F-2.3.9-006),
 *                            resent_from_campaign_id (F-2.3.9-004)
 *  §2.3.1 index              is_starred (F-2.3.1-008)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            // ---- §2.3.6 A/B testing -------------------------------------
            $table->boolean('ab_enabled')->default(false)->after('notes');
            $table->json('ab_variables')->nullable()->after('ab_enabled');
            $table->unsignedTinyInteger('ab_test_percent')->default(20)->after('ab_variables');
            $table->string('ab_winner_criteria', 24)->default('unique_opens')->after('ab_test_percent');
            $table->string('ab_decision_mode', 16)->default('window')->after('ab_winner_criteria');
            $table->unsignedSmallInteger('ab_decision_hours')->default(4)->after('ab_decision_mode');
            $table->unsignedTinyInteger('ab_confidence_level')->default(95)->after('ab_decision_hours');
            $table->boolean('ab_auto_send_winner')->default(true)->after('ab_confidence_level');

            // Runtime state (written by the send path / winner selector).
            $table->string('ab_phase', 16)->nullable()->after('ab_auto_send_winner');
            $table->dateTime('ab_test_finished_at')->nullable()->after('ab_phase');
            $table->unsignedBigInteger('ab_winner_variant_id')->nullable()->after('ab_test_finished_at');
            $table->dateTime('ab_winner_decided_at')->nullable()->after('ab_winner_variant_id');
            $table->string('ab_winner_decision', 24)->nullable()->after('ab_winner_decided_at');
            $table->double('ab_winner_p_value')->nullable()->after('ab_winner_decision');

            // ---- §2.3.7 recipient filters --------------------------------
            $table->string('filter_match', 8)->default('all')->after('ab_winner_p_value');

            // ---- §2.3.4 send options -------------------------------------
            $table->boolean('send_at_recipient_local_time')->default(false)->after('filter_match');
            $table->unsignedSmallInteger('frequency_cap_days')->nullable()->after('send_at_recipient_local_time');
            $table->string('webhook_url', 2048)->nullable()->after('frequency_cap_days');
            $table->json('webhook_events')->nullable()->after('webhook_url');
            $table->string('webhook_secret', 64)->nullable()->after('webhook_events');

            // ---- §2.3.9 lifecycle / §2.3.1 index -------------------------
            $table->dateTime('archived_at')->nullable()->after('webhook_secret');
            $table->unsignedBigInteger('resent_from_campaign_id')->nullable()->after('archived_at');
            $table->boolean('is_starred')->default(false)->after('resent_from_campaign_id');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->index(['customer_id', 'archived_at']);
            $table->index('ab_phase');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'archived_at']);
            $table->dropIndex(['ab_phase']);
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'ab_enabled', 'ab_variables', 'ab_test_percent', 'ab_winner_criteria',
                'ab_decision_mode', 'ab_decision_hours', 'ab_confidence_level',
                'ab_auto_send_winner', 'ab_phase', 'ab_test_finished_at',
                'ab_winner_variant_id', 'ab_winner_decided_at', 'ab_winner_decision',
                'ab_winner_p_value', 'filter_match', 'send_at_recipient_local_time',
                'frequency_cap_days', 'webhook_url', 'webhook_events', 'webhook_secret',
                'archived_at', 'resent_from_campaign_id', 'is_starred',
            ]);
        });
    }
};
