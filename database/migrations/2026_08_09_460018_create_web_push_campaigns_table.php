<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — web push campaigns (spec §2.10 F-2.10-010).
 *
 * The SMS campaign shape again — list + optional segment, timezone-aware
 * scheduling stored UTC (D-56), four counters — with one audience difference
 * that is inherent to the channel: a web push subscription may belong to an
 * ANONYMOUS visitor. So `audience` is explicit rather than implied:
 *
 *   all       every active subscription of the tenant, identified or not
 *   list      subscriptions whose subscriber belongs to `list_id`
 *   segment   … narrowed further by `segment_id`
 *
 * Choosing `all` is the common case for a news or e-commerce site and must not
 * require pretending anonymous browsers are list members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('name');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('icon_url', 2048)->nullable();
            $table->string('badge_url', 2048)->nullable();
            $table->string('audience', 16)->default('all'); // all|list|segment
            $table->unsignedBigInteger('list_id')->nullable();
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->unsignedInteger('ttl')->default(2419200);
            $table->string('urgency', 16)->default('normal');
            $table->string('status', 16)->default('draft'); // draft|scheduled|sending|sent|paused|cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('send_started_at')->nullable();
            $table->timestamp('send_finished_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_campaigns');
    }
};
