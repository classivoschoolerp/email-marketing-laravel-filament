<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — minimal subscriber tag system (spec §0.5 `subscriber_tags`).
 * No tag system existed anywhere in the codebase, but §2.4.2 P1 rows require
 * "added/removed a tag" triggers (F-2.4.2-007) and "add/remove tag" action
 * nodes (F-2.4.2-025), so WP-21 ships the minimal storage: one row per
 * (subscriber, tag name). Attach/detach goes through
 * App\Services\Subscribers\SubscriberTagService so tag model events feed the
 * automation trigger hooks. Names are stored trimmed + lowercased.
 * Richer tag UX (subscriber-profile tag editor, tag filters — spec
 * F-2.2.4-Tags) is P2 and deliberately not built here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('name', 191);
            $table->timestamps();

            $table->unique(['subscriber_id', 'name']);
            $table->index(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_tags');
    }
};
