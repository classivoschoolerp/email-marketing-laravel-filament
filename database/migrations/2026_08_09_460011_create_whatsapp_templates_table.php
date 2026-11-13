<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — WhatsApp message templates and their approval state
 * (spec §2.10 F-2.10-009).
 *
 * This table is the reason WhatsApp cannot reuse the SMS pipeline unchanged.
 * Outside the 24-hour customer-service window WhatsApp only delivers a
 * PRE-APPROVED template, so the platform has to model the provider's review
 * lifecycle honestly:
 *
 *   draft → (submit) → pending → approved | rejected
 *   approved → paused          (provider throttles a template with bad feedback)
 *
 * `provider_template_id` is the id the provider assigned on submission and is
 * what `syncStatus()` reads back; `rejection_reason` is the provider's own
 * text, never a guess. A campaign referencing anything but `approved` is
 * refused at schedule time (WhatsAppCampaignScheduler).
 *
 * Uniqueness is (customer_id, name, language): WhatsApp itself keys templates
 * by name + language, so two rows with the same pair would race each other on
 * the provider side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->unsignedBigInteger('whatsapp_gateway_id')->nullable();
            $table->string('name', 512); // provider rule: lowercase, digits, underscore
            $table->string('language', 16)->default('en_US');
            $table->string('category', 32)->default('marketing'); // marketing|utility|authentication
            $table->text('body');
            $table->string('header_type', 16)->default('none'); // none|text|image|video|document
            $table->string('header_text', 200)->nullable();
            $table->string('header_media_url', 2048)->nullable();
            $table->string('footer_text', 200)->nullable();
            $table->json('buttons')->nullable();
            $table->json('sample_variables')->nullable();
            $table->string('status', 16)->default('draft'); // draft|pending|approved|rejected|paused
            $table->string('rejection_reason', 500)->nullable();
            $table->string('provider_template_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'name', 'language']);
            $table->index(['customer_id', 'status']);
            $table->index('provider_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
