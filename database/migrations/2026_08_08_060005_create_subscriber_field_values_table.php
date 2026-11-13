<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-06/07 — EAV storage for custom field values (spec §2.2.9). Values are
 * stored as text; multi-value types json-encode; typed access goes through
 * App\Services\Lists\FieldValueService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->foreignId('list_field_id')->constrained('list_fields')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['subscriber_id', 'list_field_id']);
            $table->index('list_field_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_field_values');
    }
};
