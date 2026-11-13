<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — menu items (spec §3.24 F-3.24-003).
 *
 * An item stores a TARGET, never a baked URL: `type` says what it points at
 * and the matching foreign key says which row. The URL is resolved at render
 * time by MenuTree, which is what makes a renamed page slug fix every menu
 * that links to it instead of leaving a 404 behind.
 *
 * `parent_id` gives exactly one level of nesting (a dropdown). Deeper trees
 * are not modelled: nothing in the public layout renders them, and an
 * unbounded tree needs cycle protection the UI cannot express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();

            $table->string('label', 191);
            // page | category | blog | home | login | register | url
            $table->string('type', 32);

            $table->foreignId('cms_page_id')->nullable()->constrained('cms_pages')->cascadeOnDelete();
            $table->foreignId('article_category_id')->nullable()
                ->constrained('article_categories')->cascadeOnDelete();
            // Only used by type=url. Validated http(s) at save (D-100).
            $table->string('url', 2048)->nullable();

            // _self | _blank
            $table->string('target', 16)->default('_self');
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'sort_order'], 'menu_items_render_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
