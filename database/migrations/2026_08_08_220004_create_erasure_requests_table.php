<?php

declare(strict_types=1);

use App\Jobs\Subscribers\GdprEraseSubscriberJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public right-to-erasure workflow (spec §3.25.16 F-3.25.16-003 + §4.5
 * F-4.5-001, WP-22).
 *
 * Mirrors the §1.10 email-block-request shape: a public request is created
 * pending, confirmed through a signed emailed link, then reviewed in the
 * admin queue which dispatches {@see GdprEraseSubscriberJob}
 * (D-31 anonymise-in-place) for every matching subscriber.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erasure_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('email')->index();
            $table->string('status', 16)->index();
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('erased_count')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erasure_requests');
    }
};
