<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — multi-step forms (spec §2.7 F-2.7-004).
 *
 * WHY A JSON DOCUMENT AND NOT A `form_steps` TABLE.
 *
 * A step carries no identity of its own: it is a title, a description and a
 * "next" label. Nothing references a step except the fields on it, and those
 * reference it by ORDINAL (1, 2, 3…) — a number the builder already has to
 * keep contiguous, because a form whose steps are 1 and 4 is meaningless to a
 * visitor. A table would buy referential integrity for a value that is
 * inherently positional, and would cost a second foreign key on `form_fields`
 * plus a re-numbering cascade on every delete.
 *
 * `forms` already carries three normalised JSON documents — `display_rules`,
 * `frequency_rules` and `styles` — each with a normaliser class that clamps
 * every value at save AND at render (the D-100 rule). `steps` is the fourth of
 * exactly that shape, handled by {@see App\Services\Forms\FormSteps}, so it
 * inherits the same discipline rather than inventing a new one.
 *
 * `form_fields.step` is an unsigned tiny int defaulting to 1: every existing
 * field lands on step 1, which is precisely the single-step form the module
 * shipped with. A field pointing past the last defined step is clamped ONTO
 * the last step at render and at validation (never hidden), so deleting a step
 * can never make a required input silently disappear from the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->boolean('multi_step')->default(false)->after('captcha_enabled');
            $table->json('steps')->nullable()->after('multi_step');
        });

        Schema::table('form_fields', function (Blueprint $table): void {
            $table->unsignedTinyInteger('step')->default(1)->after('form_id');
        });

        Schema::table('form_fields', function (Blueprint $table): void {
            $table->index(['form_id', 'step', 'sort_order'], 'form_fields_form_step_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table): void {
            $table->dropIndex('form_fields_form_step_order_index');
        });

        Schema::table('form_fields', function (Blueprint $table): void {
            $table->dropColumn('step');
        });

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn(['multi_step', 'steps']);
        });
    }
};
