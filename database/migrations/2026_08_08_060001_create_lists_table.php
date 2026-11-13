<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-06 — subscriber lists (spec §2.2.1/§2.2.2). PHP forbids a class named
 * `List`, so the model is App\Models\EmailList on this `lists` table.
 * `uid` backs public URLs (subscribe forms/pages) in later work packages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lists', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Tab: General (F-2.2.2-001..007)
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('opt_in', 6)->default('single');
            $table->string('opt_out', 6)->default('single');
            $table->boolean('require_approval')->default(false);
            $table->string('import_duplicate_handling', 16)->default('update'); // update|skip
            $table->boolean('visible')->default(true);
            $table->json('list_tags')->nullable();

            // Tab: Defaults (F-2.2.2-008)
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('default_subject')->nullable();

            // Tab: Notifications (F-2.2.2-009/010)
            $table->boolean('subscribe_notify')->default(false);
            $table->string('subscribe_notify_emails')->nullable();
            $table->boolean('unsubscribe_notify')->default(false);
            $table->string('unsubscribe_notify_emails')->nullable();

            // Tab: Company / CAN-SPAM footer (F-2.2.2-012/013)
            $table->string('company_name')->nullable();
            $table->string('company_address_1')->nullable();
            $table->string('company_address_2')->nullable();
            $table->string('company_city', 100)->nullable();
            $table->string('company_zone', 100)->nullable();
            $table->string('company_country', 2)->nullable();
            $table->string('company_zip', 32)->nullable();
            $table->string('company_phone', 64)->nullable();
            $table->string('company_website')->nullable();
            $table->text('company_footer')->nullable();

            // Tab: Subscriber actions (F-2.2.2-015..018) — arrays of list ids.
            $table->json('on_subscribe_subscribe_ids')->nullable();
            $table->json('on_subscribe_unsubscribe_ids')->nullable();
            $table->json('on_unsubscribe_unsubscribe_ids')->nullable();
            $table->json('on_unsubscribe_subscribe_ids')->nullable();

            // Tab: Advanced (F-2.2.2-019..023). 0 = disabled per D-23.
            $table->unsignedInteger('autodelete_unconfirmed_days')->default(0);
            $table->unsignedInteger('autoremove_bounces_count')->default(0);
            $table->unsignedInteger('optin_confirm_expiry_hours')->default(0);
            $table->text('gdpr_consent_text')->nullable();
            $table->boolean('gdpr_consent_logging')->default(false);
            $table->string('default_language', 5)->default('en');

            // active | pending_delete (typed-confirmation delete queues a purge, §2.2.14)
            $table->string('status', 16)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'name']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lists');
    }
};
