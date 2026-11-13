<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — the coupon batch a campaign draws `{COUPON_CODE}` from
 * (spec §2.12 F-2.12-008).
 *
 * A typed column per D-54, and NOT a foreign key with a cascade: deleting a
 * coupon batch must not delete campaigns. `nullOnDelete` is the right
 * semantic — the campaign survives and its `{COUPON_CODE}` tag renders empty,
 * which is the honest outcome of "the codes are gone".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->foreignId('coupon_batch_id')
                ->nullable()
                ->after('goal_window_days')
                ->constrained('store_coupon_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_batch_id');
        });
    }
};
