<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-message bounce log (spec §2.9.4 F-2.9.4-009 processing log + §3.27
 * campaign bounce logs, WP-15). campaign_id/subscriber_id are nullable — a
 * DSN that cannot be attributed is still logged honestly. message_id holds
 * the ORIGINAL message's Message-ID (from the DSN's returned headers) so the
 * row can be joined to campaign_delivery_logs.message_id once WP-13's table
 * exists (that read is always Schema::hasTable-guarded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_bounce_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->foreignId('bounce_server_id')->nullable()->constrained('bounce_servers')->nullOnDelete();

            $table->string('bounce_type', 16);          // hard|soft|internal
            $table->string('email')->nullable();        // bounced recipient
            $table->string('message_id')->nullable();   // original Message-ID
            $table->text('message')->nullable();        // raw message excerpt

            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id');
            $table->index(['campaign_id', 'bounce_type']);
            $table->index(['subscriber_id', 'bounce_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_bounce_logs');
    }
};
