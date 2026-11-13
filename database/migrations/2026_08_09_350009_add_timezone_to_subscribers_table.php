<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subscriber timezone (WP-35) — the data F-2.3.4-010 "send at recipient's
 * local time" needs and which the product did not have.
 *
 * Nullable and never guessed: a subscriber whose timezone is unknown is sent
 * at the campaign's own time, which RecipientLocalTime states explicitly
 * rather than silently pretending UTC is "local". A list may also carry a
 * TIMEZONE custom field; RecipientLocalTime reads that as a fallback so
 * existing imports keep working without a re-import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
