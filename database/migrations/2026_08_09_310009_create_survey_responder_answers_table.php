<?php

declare(strict_types=1);

use App\Services\Lists\FieldValueService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — one answer per (responder, question) (spec F-2.8-005/006).
 *
 * Text-backed exactly like `subscriber_field_values`: the typed value is
 * serialised by {@see FieldValueService::toStorage()} and
 * read back through fromStorage(), so multiselect answers are JSON arrays,
 * booleans are "1"/"0" and dates are ISO — one storage convention across
 * lists, forms and surveys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responder_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('survey_responder_id')->constrained('survey_responders')->cascadeOnDelete();
            $table->foreignId('survey_field_id')->constrained('survey_fields')->cascadeOnDelete();

            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['survey_responder_id', 'survey_field_id'], 'survey_answers_unique');
            $table->index('survey_field_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responder_answers');
    }
};
