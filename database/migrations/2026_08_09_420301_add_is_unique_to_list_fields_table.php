<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — F-2.2.9-005 unique-value enforcement. A per-field opt-in flag: when
 * on, no two subscribers of the list may store the same value for the field.
 * Enforced in FieldValueService (the one authority) and wired into the public
 * subscribe form, the update-profile page and the panel's subscriber form.
 *
 * No DB unique index: subscriber_field_values stores every type as text and
 * MySQL cannot index a TEXT column without a prefix length, so the constraint
 * lives in the application layer where it can also produce a translated
 * message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_fields', function (Blueprint $table): void {
            $table->boolean('is_unique')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('list_fields', function (Blueprint $table): void {
            $table->dropColumn('is_unique');
        });
    }
};
