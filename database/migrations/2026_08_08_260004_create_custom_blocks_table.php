<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-2.3.3-026 / F-2.5.4-005 — "save & reuse custom blocks across campaigns
 * and templates" (WP-26).
 *
 * Customer-owned snippets of email-safe HTML lifted straight out of the
 * drag-and-drop builder. They are deliberately NOT tied to a template or a
 * campaign: the whole point of the row is that a block saved while editing
 * a campaign is draggable while editing a template and vice versa, so the
 * only owner is the tenant.
 *
 * html is the inlined markup GrapesJS produced for the selected component;
 * thumbnail_path is optional (the builder can post a data-URL preview, and
 * a block without one falls back to a text tile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_blocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->longText('html');
            $table->string('thumbnail_path', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_blocks');
    }
};
