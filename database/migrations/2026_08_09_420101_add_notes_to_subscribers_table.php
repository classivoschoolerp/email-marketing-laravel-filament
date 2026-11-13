<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — internal notes on a subscriber profile (spec §2.2.5 F-2.2.5-011).
 *
 * Operator-only free text: never rendered on any public page, never included
 * in the API subscriber resource (App\Http\Resources\Api\V1\SubscriberResource
 * is an explicit allow-list), and wiped by GdprEraseSubscriberJob because a
 * note an operator wrote about a person is personal data too (D-31).
 *
 * `text` rather than `string`: notes are prose, and MySQL 8 + SQLite both take
 * a plain nullable TEXT with no driver-specific syntax (D-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('unsubscribe_reason');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
