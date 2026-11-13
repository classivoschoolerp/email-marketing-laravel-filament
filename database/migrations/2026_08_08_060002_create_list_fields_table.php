<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-06 — per-list custom fields (spec §2.2.9). `validation` holds the
 * rule config json: min_length, max_length, regex, min_value, max_value,
 * min_date, max_date. `show_in_table` powers the configurable custom-field
 * columns on the subscribers index (F-2.2.4-001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('label');
            $table->string('tag', 64); // merge tag, uppercase, unique per list
            $table->text('default_value')->nullable();
            $table->string('help_text')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('visible_on_form')->default(true);
            $table->boolean('visible_on_profile')->default(true);
            $table->boolean('show_in_table')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('validation')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'tag']);
            $table->index(['list_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_fields');
    }
};
