<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — A/B tested forms (spec §2.7 F-2.7-013).
 *
 * Shaped on `landing_page_variants` (WP-34) so the two A/B features in the
 * product behave the same way: `weight` is a raw traffic share rather than a
 * percentage that must sum to 100, a declared winner is recorded on the row
 * instead of by deleting the losers, and an arm that overrides nothing is a
 * legitimate control.
 *
 * What a form arm may override:
 *  - copy: `heading`, `description`, `submit_label`;
 *  - `styles`, merged over the parent form's styling document;
 *  - `field_ids` — the interesting one. A JSON list of `form_fields.id`
 *    selecting BOTH which inputs appear and in WHAT ORDER, which is how a
 *    "3 fields vs 1 field" test is expressed. NULL means "all of the form's
 *    fields, in their own order", so adding an arm is a one-click operation.
 *    Ids that do not belong to this form are dropped at render time — the
 *    column is data, never a trusted join key.
 *
 * ATTRIBUTION, and why `form_stats_daily.form_variant_id` is NOT NULL.
 *
 * The daily counters are moved with an upsert whose conflict target is the
 * unique index `form_stats_daily_unique`. In BOTH MySQL and SQLite two NULLs
 * are distinct inside a unique index, so a nullable `form_variant_id` in that
 * index would never match on the "no variant" path: every impression would
 * insert a fresh row and the counter would stay at 1 forever. The column is
 * therefore NOT NULL with a 0 sentinel meaning "no arm" (traffic served before
 * the test existed, or while it is switched off), and the EXISTING index is
 * dropped and recreated under its own name with the column appended — one
 * index, extended, not a second conflicting one. 0 is deliberately not a
 * foreign key: it references no row by design.
 *
 * `form_submissions.form_variant_id` is a genuine nullable FK — a submission
 * row is not a counter, nothing upserts on it, and NULL there simply means the
 * visitor was not in a test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();

            $table->string('name', 191);
            $table->unsignedInteger('weight')->default(50);

            $table->string('heading', 191)->nullable();
            $table->text('description')->nullable();
            $table->string('submit_label', 120)->nullable();
            $table->json('styles')->nullable();
            $table->json('field_ids')->nullable();

            $table->boolean('is_control')->default(false);
            $table->boolean('is_winner')->default(false);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['form_id', 'enabled']);
            $table->index(['customer_id', 'form_id']);
        });

        Schema::table('forms', function (Blueprint $table): void {
            $table->boolean('ab_enabled')->default(false)->after('steps');
        });

        Schema::table('form_submissions', function (Blueprint $table): void {
            $table->foreignId('form_variant_id')->nullable()->after('form_id')
                ->constrained('form_variants')->nullOnDelete();
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            // 0 = no arm. NOT NULL because it joins a UNIQUE index used as an
            // upsert conflict target — see the class docblock.
            $table->unsignedBigInteger('form_variant_id')->default(0)->after('form_id');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            // form_id's FK constraint requires a covering index at all times;
            // form_stats_daily_unique is currently the only one, so a bare
            // dropUnique() below would fail under MySQL (works under SQLite,
            // which doesn't enforce this). This temporary index keeps the FK
            // covered across the drop/recreate and is removed once the new
            // composite unique (also form_id-leading) takes over.
            $table->index('form_id', 'form_stats_daily_form_id_index');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            $table->dropUnique('form_stats_daily_unique');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            $table->unique(['form_id', 'stat_date', 'source', 'form_variant_id'], 'form_stats_daily_unique');
            $table->index(['form_id', 'form_variant_id'], 'form_stats_daily_variant_index');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            $table->dropIndex('form_stats_daily_form_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('form_stats_daily', function (Blueprint $table): void {
            // Same FK-covering-index requirement as in up() — see there.
            $table->index('form_id', 'form_stats_daily_form_id_index');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            $table->dropIndex('form_stats_daily_variant_index');
            $table->dropUnique('form_stats_daily_unique');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            $table->dropColumn('form_variant_id');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            $table->unique(['form_id', 'stat_date', 'source'], 'form_stats_daily_unique');
        });

        Schema::table('form_stats_daily', function (Blueprint $table): void {
            $table->dropIndex('form_stats_daily_form_id_index');
        });

        Schema::table('form_submissions', function (Blueprint $table): void {
            $table->dropForeign(['form_variant_id']);
            $table->dropColumn('form_variant_id');
        });

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('ab_enabled');
        });

        Schema::dropIfExists('form_variants');
    }
};
