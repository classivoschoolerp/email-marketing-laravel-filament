<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-27A — named saved views for the subscribers index (spec §2.2.4
 * F-2.2.4-009 "Column visibility toggle + saved views").
 *
 * A view is pure UI state — the table's filter values, its toggled column
 * visibility and the search term — so it is stored as json rather than
 * modelled per filter: Filament owns the shape of that state and it changes
 * whenever a filter is added. Nothing here is ever used to build SQL directly;
 * loading a view only re-populates the Livewire table state, which is then
 * re-validated by the table's own filters.
 *
 * Tenant-scoped by customer_id (D-05) with names unique per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 191);
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->string('search')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_saved_views');
    }
};
