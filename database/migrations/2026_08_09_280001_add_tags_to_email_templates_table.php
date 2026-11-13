<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-27B — template tags (spec §2.5.1 F-2.5.1-002 "Filter by category, tag,
 * mine vs system gallery").
 *
 * Resolves docs/DECISIONS.md D-34, which parked the row because "templates have
 * categories but no tag field in schema; add tags column later or accept
 * category-only". The smaller, more useful option was taken: a `tags` JSON
 * column rather than a tags table + pivot.
 *
 * Why a JSON column and not a pivot:
 *   • tags here are free-text labels owned by one template, never entities
 *     with their own attributes/permissions — a pivot would add two tables
 *     and a join for no behaviour;
 *   • tenancy comes for free: the tags live ON the row, which is already
 *     fenced by CustomerScope, so no second table needs its own customer_id
 *     and its own scope to keep tenants apart;
 *   • the codebase already filters JSON columns portably
 *     (EmailTemplate::scopeBrowsable uses whereJsonContains on
 *     gallery_customer_groups), so SQLite and MySQL 8 both work unchanged.
 *
 * Values are normalised (trimmed, lower-cased, de-duplicated) by
 * App\Support\Templates\TemplateTags before they are written, so
 * whereJsonContains is an exact, case-insensitive-by-construction match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table): void {
            $table->dropColumn('tags');
        });
    }
};
