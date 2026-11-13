<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2.13 CRM (light) — the contact record and the organisation it belongs to
 * (WP-44, F-2.13-001 / -002 / -008).
 *
 * ── Why `crm_contacts` is NOT a second subscribers table (D-115) ───────────
 * `subscribers` is a LIST MEMBERSHIP: the same human on three lists is three
 * rows, each with its own status, subscribe date, consent record and tracking
 * uid. That shape is correct for email and must not change.
 *
 * A CRM contact is the PERSON. One row per (customer_id, email) — enforced by
 * the unique index below — carrying only what a CRM owns and a list
 * membership cannot express: the company, the owner, the lifecycle stage, the
 * lead score. Everything a subscriber row already knows (per-list status,
 * consent, engagement, custom field values, the whole open/click history) is
 * READ from `subscribers` and the tracking tables through
 * App\Services\Crm\ContactSubscribers — never copied here. Delete the CRM
 * module and no email data is lost; delete a list and the contact survives
 * with its deal history intact.
 *
 * Tables:
 *  - crm_companies         F-2.13-002 organisations
 *  - crm_contacts          F-2.13-001 the unified record
 *  - crm_lifecycle_events  F-2.13-008 transition history
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('industry')->nullable();
            $table->string('size', 32)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('website')->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 2)->nullable();

            // Owner = a team member (WP-39 sub_users); NULL means the account
            // owner, which is also the fallback when a member is deleted.
            $table->foreignId('owner_sub_user_id')->nullable()
                ->constrained('sub_users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'name']);
            $table->index(['customer_id', 'domain']);
        });

        Schema::create('crm_contacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Lower-cased at the model boundary; the identity of the record.
            $table->string('email');

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('job_title')->nullable();

            $table->foreignId('company_id')->nullable()
                ->constrained('crm_companies')->nullOnDelete();
            $table->foreignId('owner_sub_user_id')->nullable()
                ->constrained('sub_users')->nullOnDelete();

            // F-2.13-008: subscriber → lead → mql → sql → customer.
            $table->string('lifecycle_stage', 16)->default('subscriber');
            $table->timestamp('lifecycle_changed_at')->nullable();

            // F-2.13-007: the computed 0..100 score plus the two inputs the
            // recompute cannot derive — a manual/automation adjustment and
            // when it was applied (it decays from that instant).
            $table->unsignedTinyInteger('lead_score')->default(0);
            $table->integer('lead_score_manual')->default(0);
            $table->timestamp('lead_score_manual_at')->nullable();
            $table->timestamp('lead_score_computed_at')->nullable();

            $table->string('source', 32)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One contact per address per tenant — the whole point of the row.
            $table->unique(['customer_id', 'email']);
            $table->index(['customer_id', 'lifecycle_stage']);
            $table->index(['customer_id', 'lead_score']);
            $table->index(['customer_id', 'owner_sub_user_id']);
        });

        Schema::create('crm_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();

            $table->string('from_stage', 16)->nullable();
            $table->string('to_stage', 16);
            // manual | automation | rule | deal_won | import
            $table->string('reason', 32)->default('manual');
            $table->string('actor_name')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'contact_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lifecycle_events');
        Schema::dropIfExists('crm_contacts');
        Schema::dropIfExists('crm_companies');
    }
};
