<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — menu builder for the public site (spec §3.24 F-3.24-003).
 *
 * A menu is operator-owned, not tenant-owned: it is the marketing site's own
 * navigation, so there is no `customer_id` here. It IS brand-fenced via
 * `domain_id`, exactly like `articles` and `cms_pages` (D-107): a menu bound
 * to brand A must never render on brand B's host, and a NULL `domain_id`
 * menu is the shared fallback every brand inherits until it defines its own.
 *
 * `location` is a small closed set (`Menu::LOCATIONS`) rather than free text
 * so the layout can ask for a named slot and get either one menu or nothing.
 * The unique index makes "which header menu?" unambiguous per brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $table->string('name', 191);
            // header | footer
            $table->string('location', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['domain_id', 'location'], 'menus_domain_location_unique');
            $table->index(['location', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
