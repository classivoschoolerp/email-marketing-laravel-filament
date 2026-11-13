<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand kit — saved colours, fonts and logo (spec §2.5.4 F-2.5.4-004, WP-42).
 *
 * Shaped exactly like `custom_blocks` (D-86): tenant-owned, with NO template
 * or campaign foreign key. That absence is the feature — a brand kit saved
 * while editing a template is the same brand kit the campaign content builder
 * offers, because the only owner is the tenant.
 *
 * One row per customer (unique customer_id): a brand kit is the tenant's
 * identity, not a collection of presets.
 *
 * `colors` is an ordered list of {name, value} pairs; `fonts` is a map of
 * role (heading/body) → whitelisted email-safe stack key; `logo_path` is a
 * path on the `public` disk under customers/{uid}/brand/ (D-26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->json('colors')->nullable();
            $table->json('fonts')->nullable();
            $table->string('logo_path', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_kits');
    }
};
