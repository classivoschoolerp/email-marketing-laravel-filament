<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 — per-install admin defaults for the 11 list page types (spec §3.16).
 * Sits between the hardcoded translatable defaults (lang/…/lists.php) and the
 * per-list overrides (list_pages) in ListPageResolver's 3-layer merge:
 *
 *   per-list override > admin default (this table) > translatable default
 *
 * Global (no customer_id): these are the operator's install-wide defaults
 * that new/uncustomized lists inherit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_page_defaults', function (Blueprint $table): void {
            $table->id();

            // App\Enums\ListPageType value.
            $table->string('type', 32)->unique();
            $table->string('subject')->nullable();
            $table->longText('content')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_page_defaults');
    }
};
