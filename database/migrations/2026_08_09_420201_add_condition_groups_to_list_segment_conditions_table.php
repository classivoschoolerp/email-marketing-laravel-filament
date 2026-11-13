<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — nested condition groups (spec §2.2.10 F-2.2.10-011, "AND of ORs").
 *
 * Minimal storage extension: every condition row carries the index of the
 * group it belongs to and, optionally, that group's own ALL/ANY operator.
 *
 *   top level  = the segment's match_type combined ACROSS groups
 *   inside one group = `group_match` (falling back to the segment match_type)
 *
 * Every existing row defaults to group 0 with a null group_match, which is
 * exactly one group evaluated with the segment's match_type — i.e. flat
 * segments keep evaluating byte-for-byte identically (regression-tested in
 * tests/Feature/Segments/SegmentConditionGroupsTest.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_segment_conditions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('group_index')->default(0)->after('sort_order');
            $table->string('group_match', 3)->nullable()->after('group_index'); // all|any|null
        });
    }

    public function down(): void
    {
        Schema::table('list_segment_conditions', function (Blueprint $table): void {
            $table->dropColumn(['group_index', 'group_match']);
        });
    }
};
