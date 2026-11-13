<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-34 — landing-page conversions (spec §2.6 F-2.6-008/009, §1.8 F-1.8-003).
 *
 * A conversion is a completed submission of the page's embedded §2.7 form.
 * The form module owns the subscriber write and its own `form_submissions`
 * row; this table is the LANDING-side fact, carrying the variant so the A/B
 * comparison (F-2.6-004) can be computed from one join, and the visitor key
 * so a conversion can be attributed to the visit that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('landing_pages')->cascadeOnDelete();
            $table->foreignId('landing_page_variant_id')->nullable()
                ->constrained('landing_page_variants')->nullOnDelete();
            $table->foreignId('landing_page_visit_id')->nullable()
                ->constrained('landing_page_visits')->nullOnDelete();

            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->foreignId('form_submission_id')->nullable()
                ->constrained('form_submissions')->nullOnDelete();

            $table->string('email', 191)->nullable();
            $table->string('source', 64)->default('direct');
            $table->string('visitor_key', 64)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['landing_page_id', 'created_at']);
            // Explicit name: the generated one would be 65 characters, one
            // over MySQL's 64-character identifier limit (D-03 keeps every
            // migration MySQL-compatible even though dev runs on SQLite).
            $table->index(['landing_page_variant_id', 'created_at'], 'landing_conversions_variant_created_index');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_conversions');
    }
};
