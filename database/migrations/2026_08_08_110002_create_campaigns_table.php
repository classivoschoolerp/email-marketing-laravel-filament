<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns (spec §2.3, WP-11/12).
 *
 * Option storage decision: dedicated columns (not a key-value side table) —
 * every §2.3.4 option is queried/validated individually by the wizard,
 * the WP-13 sending engine and reports, so typed columns beat generic JSON.
 * Only genuinely list-valued options are json.
 *
 * Cross-module seams (deliberately NOT foreign keys):
 *  - delivery_server_ids json — delivery servers are built by WP-14/15 in
 *    parallel; the wizard stores plain ids and reads the `delivery_servers`
 *    table behind a Schema::hasTable guard (WP-13 consumes).
 *  - tracking_domain_id — `tracking_domains` table arrives with WP-15.
 *  - suppression_list_ids json — suppression lists arrive with WP-16.
 *  - delivery_* counters + send_started_at/send_finished_at are written by
 *    the WP-13 sending engine; the index progress bar reads them (zeros
 *    until then).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('campaign_group_id')->nullable()->constrained('campaign_groups')->nullOnDelete();
            $table->foreignId('email_list_id')->nullable()->constrained('lists')->nullOnDelete();
            $table->foreignId('list_segment_id')->nullable()->constrained('list_segments')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('email_templates')->nullOnDelete();

            // §2.3.2 step 1 — details.
            $table->string('name');
            $table->string('type', 16)->default('regular');
            $table->string('status', 24)->default('draft');
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('to_name')->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('preheader', 500)->nullable();
            $table->dateTime('send_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('send_immediately')->default(false);

            // §2.3.2 — autoresponder configuration.
            $table->string('ar_trigger', 32)->nullable();
            $table->unsignedBigInteger('ar_trigger_campaign_id')->nullable()->index();
            $table->foreignId('ar_date_field_id')->nullable()->constrained('list_fields')->nullOnDelete();
            $table->unsignedInteger('ar_delay_value')->nullable();
            $table->string('ar_delay_unit', 16)->nullable();
            $table->time('ar_send_time')->nullable();
            $table->string('ar_apply_to', 16)->nullable();
            $table->boolean('ar_once_per_subscriber')->default(true);

            // §2.3.2 — recurring configuration.
            $table->string('rec_interval', 16)->nullable();
            $table->string('rec_cron', 120)->nullable();
            $table->json('rec_days_of_week')->nullable();
            $table->unsignedTinyInteger('rec_day_of_month')->nullable();
            $table->dateTime('rec_starts_at')->nullable();
            $table->dateTime('rec_ends_at')->nullable();
            $table->unsignedInteger('rec_max_occurrences')->nullable();
            $table->unsignedInteger('rec_occurrences')->default(0);
            $table->dateTime('next_run_at')->nullable()->index();

            // §2.3.3 step 2 — content.
            $table->string('builder', 32)->nullable();
            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();
            $table->longText('plain_text')->nullable();
            $table->boolean('auto_plain_text')->default(true);

            // §2.3.4 step 3 — tracking.
            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);
            $table->json('url_tracking_exceptions')->nullable();
            $table->boolean('utm_enabled')->default(false);
            $table->json('utm_params')->nullable();
            $table->unsignedBigInteger('tracking_domain_id')->nullable();

            // §2.3.4 — delivery.
            $table->json('delivery_server_ids')->nullable();
            $table->unsignedInteger('max_emails')->nullable();
            $table->unsignedInteger('send_rate_per_hour')->nullable();
            $table->json('send_window_days')->nullable();
            $table->time('send_window_start')->nullable();
            $table->time('send_window_end')->nullable();
            $table->unsignedInteger('batch_size')->nullable();
            $table->unsignedInteger('batch_interval_seconds')->nullable();
            $table->json('suppression_list_ids')->nullable();

            // §2.3.4 — content options.
            $table->boolean('embed_images')->default(false);
            $table->boolean('plain_text_only')->default(false);
            $table->json('custom_headers')->nullable();

            // §2.3.4 — extras.
            $table->boolean('share_reports')->default(false);
            $table->string('share_reports_secret', 64)->nullable()->unique();
            $table->boolean('notify_on_finish')->default(false);
            $table->text('notes')->nullable();

            // WP-13 delivery counters (progress bar / stats seam).
            $table->unsignedInteger('delivery_total')->default(0);
            $table->unsignedInteger('delivery_sent')->default(0);
            $table->unsignedInteger('delivery_failed')->default(0);
            $table->dateTime('send_started_at')->nullable();
            $table->dateTime('send_finished_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'type']);
            $table->index('send_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
