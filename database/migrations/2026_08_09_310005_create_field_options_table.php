<?php

declare(strict_types=1);

use App\Services\Lists\FieldValueService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — choice options for form and survey fields (spec F-2.2.9-003 reused
 * by §2.7/§2.8).
 *
 * `list_field_options` stays exactly as WP-06/07 built it; form and survey
 * fields share ONE polymorphic table instead of two near-identical ones, so
 * the option manager, the public renderer and
 * {@see FieldValueService} see the same `options`
 * relation contract (value + label + sort_order) on all three field models.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('owner_type', 64);
            $table->unsignedBigInteger('owner_id');

            $table->string('label', 191);
            $table->string('value', 191);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'sort_order'], 'field_options_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_options');
    }
};
