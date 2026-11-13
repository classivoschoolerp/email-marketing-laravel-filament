<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — §2.9.12 result tagging stored on the subscriber (F-2.9.12-006):
 * verification_status ∈ deliverable|undeliverable|risky|unknown (null =
 * never verified) + verified_at timestamp of the last verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->string('verification_status', 16)->nullable()->after('complaints_count');
            $table->timestamp('verified_at')->nullable()->after('verification_status');

            $table->index(['list_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropIndex(['list_id', 'verification_status']);
            $table->dropColumn(['verification_status', 'verified_at']);
        });
    }
};
