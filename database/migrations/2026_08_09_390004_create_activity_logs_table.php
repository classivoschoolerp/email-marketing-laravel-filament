<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2.15.7 account activity log (WP-39).
 *
 * Chronological record of what the ACCOUNT did — created a list, sent a
 * campaign, changed a setting, deleted a subscriber — filterable by user,
 * action type and date, and exportable.
 *
 * Deliberately NOT a re-implementation of `login_logs` (auth events) or
 * `consent_events` (subscriber consent) or `impersonation_logs` (admin
 * access): those stay the authority for their domains and the activity log
 * never mirrors them, so nothing is double-counted.
 *
 * `actor_*` is a nullable morph so a row survives the deletion of the
 * sub-user who caused it (same rule as impersonation_logs); actor_name is
 * denormalised for exactly that case. created_at only — rows are immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->nullableMorphs('actor');
            $table->string('actor_name')->nullable();

            // Dotted action key, e.g. 'list.created', 'campaign.sent'.
            $table->string('action', 64);

            $table->nullableMorphs('subject');
            $table->string('subject_label')->nullable();

            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'created_at']);
            $table->index(['customer_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
