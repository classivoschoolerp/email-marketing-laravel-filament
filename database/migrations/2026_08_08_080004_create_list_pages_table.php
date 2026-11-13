<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-08 — per-list public page content (spec §2.2.12 / §1.5). A row exists
 * only once a page is customized; ListPageResolver falls back to the
 * translatable defaults otherwise ("reset to default" simply deletes the
 * row). `subject` applies to the email page types (double opt-in confirm +
 * welcome email); `redirect_url` is the per-page redirect override
 * (F-2.2.12-005). Public rendering is WP-09's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();

            // App\Enums\ListPageType value (the 11 P1 page types of §1.5).
            $table->string('type', 32);
            $table->string('subject')->nullable();
            $table->longText('content')->nullable();
            $table->string('redirect_url')->nullable();

            $table->timestamps();

            $table->unique(['list_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_pages');
    }
};
