<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — WhatsApp campaigns (spec §2.10 F-2.10-009).
 *
 * Column-for-column the SMS campaign shape (list + optional segment, the phone
 * value read from a list custom field, timezone-aware scheduling stored UTC per
 * D-56, the four delivery counters) plus the three things that are WhatsApp and
 * only WhatsApp:
 *
 *   whatsapp_template_id — the approved template this send uses. NOT nullable
 *                          in practice: the scheduler refuses a campaign whose
 *                          template is not `approved`.
 *   variables            — ordered map "1" => "{FNAME|there}" of the template's
 *                          {{n}} placeholders to merge tags, rendered per
 *                          recipient at accept time.
 *   header_media_url     — per-campaign override for a media header, so one
 *                          approved template can carry a different image each
 *                          send (which is exactly how the provider intends
 *                          media headers to be used).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('name');
            $table->unsignedBigInteger('list_id')->nullable();
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->unsignedBigInteger('phone_field_id')->nullable();
            $table->unsignedBigInteger('whatsapp_gateway_id')->nullable();
            $table->unsignedBigInteger('whatsapp_template_id')->nullable();
            $table->json('variables')->nullable();
            $table->string('header_media_url', 2048)->nullable();
            $table->string('status', 16)->default('draft'); // draft|scheduled|sending|sent|paused|cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('read_count')->default(0);
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
        Schema::dropIfExists('whatsapp_campaigns');
    }
};
