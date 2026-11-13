<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-06 — option rows for choice field types (dropdown / multiselect /
 * checkbox list / radio) — spec F-2.2.9-003 (name + value, sortable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_field_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_field_id')->constrained('list_fields')->cascadeOnDelete();
            $table->string('label');
            $table->string('value');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['list_field_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_field_options');
    }
};
