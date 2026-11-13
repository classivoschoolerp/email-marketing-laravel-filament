<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Services\Lists\FieldValueService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — form builder fields (spec F-2.7-003).
 *
 * Deliberately the same column shape as `list_fields` (WP-06/07): the whole
 * §2.2.9 field-type library ({@see ListFieldType}, 18 types) is
 * reused unchanged, so {@see FieldValueService} validates
 * and serialises a form field with the very same code path as a list field.
 *
 * `list_field_id` links the input to the list custom field the answer is
 * stored in. It is required for anything that must land on the subscriber;
 * unlinked fields are submission-only metadata (kept on form_submissions.data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
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

            $table->unique(['form_id', 'tag']);
            $table->index(['form_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
