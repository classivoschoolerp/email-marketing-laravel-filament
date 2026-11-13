<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 — public "never email me again" requests (spec §1.10 / §3.19).
 * Lifecycle: pending (form submitted) → confirmed (signed email link
 * clicked) → approved (admin, or auto-approve; inserts into
 * global_blacklist_emails) | rejected (admin).
 *
 * Global table — block requests are install-wide, not tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_block_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('email')->index();

            // App\Enums\BlockRequestStatus: pending|confirmed|approved|rejected
            $table->string('status', 16)->default('pending')->index();

            $table->string('ip', 45)->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_block_requests');
    }
};
