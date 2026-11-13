<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-13 — current send wave handle (F-0.4-001 Bus::batch progress). Holds the
 * Bus batch id while a wave is in flight ('pending' between claim and batch
 * creation); cleared by the finisher on pause/cancel so campaigns:send can
 * start a resume wave for the remaining recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('send_batch_id', 64)->nullable()->after('delivery_failed');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('send_batch_id');
        });
    }
};
