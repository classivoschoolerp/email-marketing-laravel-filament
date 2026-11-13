<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-08 — embed form configuration (spec §2.2.11). One row per list (the
 * embed form is a per-list singleton) on its own table rather than columns on
 * `lists` so the WP-06 lists migration stays untouched — see docs/DECISIONS.md
 * (WP-08). `field_ids` is the ordered subset of the list's visible_on_form
 * fields to render (F-2.2.11-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_forms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->unique()->constrained('lists')->cascadeOnDelete();

            // Ordered list of list_field ids shown on the form (F-2.2.11-002).
            $table->json('field_ids')->nullable();
            // Styled vs raw markup (F-2.2.11-004).
            $table->boolean('styled')->default(true);
            // Success/error redirect URLs (F-2.2.11-005).
            $table->string('success_url')->nullable();
            $table->string('error_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_forms');
    }
};
