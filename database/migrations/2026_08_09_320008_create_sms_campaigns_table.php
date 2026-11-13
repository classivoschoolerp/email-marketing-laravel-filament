<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — SMS campaigns (spec §2.10 F-2.10-001/004/006).
 *
 * The audience is an email list + optional segment (the same audience model
 * as email campaigns); the recipient's number comes from the list custom
 * field named by `phone_field_id` (ListFieldType::Phone). Scheduling carries
 * its own `timezone` and stores `scheduled_at` in UTC, exactly like campaigns
 * (D-56 maths-in-campaign-TZ convention).
 *
 * `body` holds merge tags; the character/segment counter and unicode
 * detection in the UI are computed by App\Sms\SegmentCounter, and the same
 * class stamps `segments`/`encoding` on every sms_logs row at send time so
 * the report shows what was actually billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('name');
            $table->unsignedBigInteger('list_id')->nullable();
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->unsignedBigInteger('phone_field_id')->nullable();
            $table->unsignedBigInteger('sms_gateway_id')->nullable();
            $table->string('sender_id')->nullable();
            $table->text('body');
            $table->string('status', 16)->default('draft'); // draft|scheduled|sending|sent|paused|cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('shorten_links')->default(true);
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('send_started_at')->nullable();
            $table->timestamp('send_finished_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_campaigns');
    }
};
