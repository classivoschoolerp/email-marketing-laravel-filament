<?php

declare(strict_types=1);

use App\Services\Lists\FieldValueService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — survey questions (spec F-2.8-003).
 *
 * Same column shape as `list_fields` / `form_fields` so the §2.2.9 field-type
 * library and {@see FieldValueService} apply unchanged.
 *
 * `list_field_id` is the optional write-back target: when an IDENTIFIED
 * responder answers a mapped question, the answer is also stored on the
 * subscriber, which is what lets F-2.8-007 build a segment from the answer
 * with the existing (dynamic) SegmentQueryBuilder instead of a bespoke
 * survey-only query language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->foreignId('list_field_id')->nullable()
                ->constrained('list_fields')->nullOnDelete();

            $table->string('type', 32);
            $table->string('tag', 64);
            $table->string('label', 191);
            $table->string('placeholder', 191)->nullable();
            $table->string('default_value', 1000)->nullable();
            $table->string('help_text', 191)->nullable();
            $table->boolean('required')->default(false);
            $table->json('validation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['survey_id', 'tag']);
            $table->index(['survey_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_fields');
    }
};
