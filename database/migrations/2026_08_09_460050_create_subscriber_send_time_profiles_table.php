<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Send Time Optimization profiles (spec §2.3.4 F-2.3.4-011, WP-46).
 *
 * One row per subscriber: the day-of-week × hour engagement histogram learned
 * from that person's OWN opens and clicks, plus the hour it points at. The row
 * is a CACHE of a computation over campaign_track_opens/clicks — it is always
 * recomputable from the tracking tables, and `computed_at` + `events` make
 * staleness and thinness visible to every reader.
 *
 * Nothing here is guessed. `best_hour` is NULL whenever the subscriber's own
 * history is too thin to speak (see SendTimeProfiler::MIN_EVENTS), and `source`
 * records WHICH of the documented fallbacks produced the row, so a report can
 * never present an account-wide average as a personal prediction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_send_time_profiles', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            // Denormalised so a refresh can be scoped to one list cheaply.
            $table->unsignedBigInteger('list_id')->nullable();

            // The timezone the histogram is expressed in. NULL = UTC, which is
            // what happens when subscribers.timezone was never recorded —
            // never guessed from the IP (the RecipientLocalTime rule).
            $table->string('timezone', 64)->nullable();

            // 0..23 and 0..6 (Carbon dayOfWeek: 0 = Sunday), in `timezone`.
            $table->unsignedTinyInteger('best_hour')->nullable();
            $table->unsignedTinyInteger('best_day')->nullable();

            // Share of total weight sitting in the winning hour, 0..1. This is
            // a concentration measure, NOT a probability of opening.
            $table->double('confidence')->default(0);

            // Raw event count and decayed weight that produced the profile.
            $table->unsignedInteger('events')->default(0);
            $table->double('weight_total')->default(0);

            // {"hours": {"0": w, …}, "days": {"0": w, …}} — the read-out.
            $table->json('histogram')->nullable();

            // subscriber | account | none — see SendTimeProfiler.
            $table->string('source', 16)->default('none');

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique('subscriber_id');
            $table->index(['customer_id', 'computed_at']);
            $table->index(['list_id', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_send_time_profiles');
    }
};
