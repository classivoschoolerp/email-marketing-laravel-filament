<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — delivery + engagement log for automation "send email" nodes
 * (F-2.4.2-020, report rows F-2.4.3-002/003). Mirrors the WP-13
 * campaign_delivery_logs claim pattern: AutomationSendJob inserts the row as
 * `retrying` to claim the (run, node) send — the unique index is the second
 * idempotency arbiter behind the `automation-send:{run}:{node}` job key —
 * then finalizes to sent|failed|skipped.
 *
 * Opens/clicks are tracked by WP-21's own lightweight pixel/redirect
 * endpoints (routes/modules/automations.php) because the WP-09 tracking
 * endpoints are campaign-bound; `uid` is the HMAC-signed token in those
 * URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_email_sends', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('automation_nodes')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('email');
            $table->string('subject', 500)->nullable();
            $table->string('status', 16); // retrying|sent|failed|skipped
            $table->string('message_id')->nullable();
            $table->text('response')->nullable();
            $table->unsignedSmallInteger('retries')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->string('last_click_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'node_id']);
            $table->index(['automation_id', 'node_id', 'status'], 'automation_email_sends_node_index');
            $table->index(['subscriber_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_email_sends');
    }
};
