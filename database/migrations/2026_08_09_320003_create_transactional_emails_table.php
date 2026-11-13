<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — transactional email templates (spec §2.11 F-2.11-001/008).
 *
 * Deliberately a SEPARATE table from email_templates: campaign templates are
 * gallery/builder artefacts with revisions, categories and a marketing
 * lifecycle, while these are API-addressable payload templates identified by
 * a stable per-customer `slug` (that is what `send-template` posts) and
 * carrying their own envelope (subject/from/reply-to) plus a declared
 * `variables` list of placeholder names for the UI and for validation.
 *
 * Dedicated IP (F-2.11-008) is `dedicated_ip` + `delivery_server_ids`: the
 * flag pins the send to the listed delivery server(s)/pool instead of the
 * customer's general rotation, exactly like campaigns.delivery_server_ids
 * (D-54). Empty list with the flag on = misconfiguration, and the sender
 * fails the message honestly rather than silently falling back to shared IPs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactional_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('slug', 191);
            $table->string('name');
            $table->string('subject');
            $table->longText('content_html')->nullable();
            $table->longText('plain_text')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->json('variables')->nullable();
            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);
            $table->boolean('dedicated_ip')->default(false);
            $table->json('delivery_server_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'slug']);
            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_emails');
    }
};
