<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2.13 F-2.13-004 (tasks, reminders, meeting scheduler) and F-2.13-005
 * (notes & call logs) — WP-44.
 *
 * One table per concept rather than a polymorphic "activity" blob, because
 * the two have genuinely different columns: a task has a due date, a reminder
 * and a completion state; a call log has a direction, an outcome and a
 * duration. Both hang off the same three optional parents (contact, company,
 * deal) so either can be filed anywhere in the CRM and both feed the
 * F-2.13-006 timeline.
 *
 * The meeting scheduler is `type = meeting`: a task with a duration, a
 * location/URL and an ICS export (routes/modules/crm.php).
 *
 * Reminders: `remind_at` is when the reminder is DUE, `reminded_at` is when it
 * was actually sent. `crm:send-reminders` only ever touches rows where the
 * second is null, so a reminder is sent exactly once even if the command runs
 * twice in the same minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // task | call | meeting
            $table->string('type', 12)->default('task');

            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('contact_id')->nullable()
                ->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->constrained('crm_companies')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()
                ->constrained('crm_deals')->nullOnDelete();
            $table->foreignId('owner_sub_user_id')->nullable()
                ->constrained('sub_users')->nullOnDelete();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminded_at')->nullable();

            // Meeting scheduler extras.
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('location')->nullable();
            $table->string('meeting_url')->nullable();

            // open | completed | cancelled
            $table->string('status', 12)->default('open');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status', 'due_at']);
            $table->index(['customer_id', 'contact_id']);
            // The reminder sweep's exact predicate.
            $table->index(['reminded_at', 'remind_at']);
        });

        Schema::create('crm_notes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // note | call
            $table->string('type', 8)->default('note');

            $table->foreignId('contact_id')->nullable()
                ->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('company_id')->nullable()
                ->constrained('crm_companies')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()
                ->constrained('crm_deals')->nullOnDelete();
            $table->foreignId('author_sub_user_id')->nullable()
                ->constrained('sub_users')->nullOnDelete();

            $table->text('body');

            // Call log columns (null on a plain note).
            $table->string('call_direction', 8)->nullable();
            $table->string('call_outcome', 16)->nullable();
            $table->unsignedInteger('call_duration_seconds')->nullable();

            // Author name is denormalised so a revoked member still narrates
            // the history they wrote (same reasoning as activity_logs).
            $table->string('author_name')->nullable();

            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'contact_id', 'occurred_at']);
            $table->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notes');
        Schema::dropIfExists('crm_tasks');
    }
};
