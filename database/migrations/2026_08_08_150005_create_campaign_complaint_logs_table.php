<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-complaint (FBL/ARF) log (spec §2.9.5 F-2.9.5-004 complaint logs per
 * campaign/subscriber + §3.27, WP-15). Nullable attribution mirrors
 * campaign_bounce_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_complaint_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->foreignId('fbl_server_id')->nullable()->constrained('fbl_servers')->nullOnDelete();

            $table->string('feedback_type', 32)->nullable(); // ARF Feedback-Type (abuse, fraud, ...)
            $table->string('email')->nullable();             // complaining recipient
            $table->string('message_id')->nullable();        // original Message-ID
            $table->text('message')->nullable();             // raw report excerpt

            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id');
            $table->index(['campaign_id', 'feedback_type']);
            $table->index('subscriber_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_complaint_logs');
    }
};
