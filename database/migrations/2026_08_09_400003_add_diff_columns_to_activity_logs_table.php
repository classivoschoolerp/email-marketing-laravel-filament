<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-40 — §3.27 F-3.27-011 "Audit log — who changed what, before/after diff".
 *
 * The WP-39 `activity_logs` table records WHICH attributes changed
 * (`properties.changed`) but not what they changed FROM and TO, so no diff
 * could be rendered. Two additive JSON columns close that: `old_values` and
 * `new_values` carry the same key set, and the viewer renders one row per
 * key. Existing rows keep NULL and the viewer says so rather than inventing
 * an empty diff.
 *
 * Values are recorded by ActivityLogObserver, which already filters noise
 * attributes; the observer additionally redacts anything that looks like a
 * secret so an audit trail never becomes a credential leak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->json('old_values')->nullable()->after('properties');
            $table->json('new_values')->nullable()->after('old_values');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropColumn(['old_values', 'new_values']);
        });
    }
};
