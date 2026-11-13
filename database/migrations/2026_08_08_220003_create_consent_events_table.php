<?php

declare(strict_types=1);

use App\Observers\ConsentEventObserver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of consent changes (spec §4.5 F-4.5-008, WP-22).
 *
 * One append-only row per change to a Consent-type custom field value
 * (spec §2.2.9 ListFieldType::Consent), written by
 * {@see ConsentEventObserver}. `field` stores the merge tag
 * so the row survives the field being renamed or deleted; old/new hold the
 * literal stored values ("1"/"0"/NULL). Retention is enforced by
 * `logs:prune` using the §3.25.15 audit-log window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('subscriber_id')->index();
            $table->unsignedBigInteger('list_field_id')->nullable()->index();
            $table->string('field', 64);
            $table->string('old_value', 32)->nullable();
            $table->string('new_value', 32)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('source', 32)->index();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['subscriber_id', 'created_at'], 'consent_events_subscriber_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');
    }
};
