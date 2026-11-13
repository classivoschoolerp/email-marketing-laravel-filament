<?php

declare(strict_types=1);

use App\Services\Forms\DisplayRules;
use App\Services\Forms\FormStyles;
use App\Services\Forms\FrequencyRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — standalone forms & popups (spec §2.7).
 *
 * A form is a tenant-owned, list-targeted capture surface rendered in one of
 * six shapes (F-2.7-002). Display rules (F-2.7-005), frequency rules
 * (F-2.7-006) and the styling editor (F-2.7-007) are JSON documents whose
 * canonical key sets live in {@see DisplayRules},
 * {@see FrequencyRules} and
 * {@see FormStyles} — the same payload the embed loader
 * (F-2.7-011) receives, so the browser honours exactly what the builder saved.
 *
 * `uid` is the public access key (MailWizz pattern, same as lists/campaigns):
 * every public endpoint resolves the form by uid, never by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();

            $table->string('name', 191);
            // embedded|popup|slide_in|floating_bar|full_screen|exit_intent (F-2.7-002)
            $table->string('type', 32)->default('embedded');
            // draft|active|inactive
            $table->string('status', 16)->default('draft');

            // ---- content -------------------------------------------------
            $table->string('heading', 191)->nullable();
            $table->text('description')->nullable();
            $table->string('submit_label', 120)->nullable();
            $table->string('email_label', 120)->nullable();

            // ---- success handling (F-2.7-008) ----------------------------
            // message|redirect|download
            $table->string('success_mode', 16)->default('message');
            $table->text('success_message')->nullable();
            $table->string('redirect_url', 2048)->nullable();
            $table->string('lead_magnet_url', 2048)->nullable();
            $table->string('lead_magnet_label', 191)->nullable();

            // ---- GDPR consent (F-2.7-009) --------------------------------
            $table->boolean('consent_enabled')->default(false);
            $table->boolean('consent_required')->default(true);
            $table->text('consent_text')->nullable();
            // Optional Consent-type list field the tick is written to, so the
            // WP-22 ConsentEventObserver records it exactly like any other
            // consent change. NULL = the submission logs its own event.
            $table->foreignId('consent_list_field_id')->nullable()
                ->constrained('list_fields')->nullOnDelete();

            // ---- abuse controls (F-2.7-010) ------------------------------
            $table->boolean('captcha_enabled')->default(true);

            // ---- JSON rule documents -------------------------------------
            $table->json('display_rules')->nullable();   // F-2.7-005
            $table->json('frequency_rules')->nullable(); // F-2.7-006
            $table->json('styles')->nullable();          // F-2.7-007

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
