<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — saved custom report definitions (spec §4.2 F-4.2-010).
 *
 * A definition is a QUERY, not a result: source dataset + group-by dimensions
 * + measures + a condition tree + sort/limit. Nothing computed is stored here,
 * so re-running a saved report always reflects today's data (the same rule
 * that makes §2.2.10 dynamic segments re-evaluate at read time).
 *
 * The column is `row_limit`, not `limit` — `limit` is a reserved word in
 * MySQL 8 and would need quoting at every touch point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // ReportDataset value: campaigns|subscribers|links|opens
            $table->string('source', 32)->default('campaigns');

            // AnalyticsRange: preset key, plus the two bounds when key=custom.
            $table->string('range_key', 16)->default('30d');
            $table->date('range_from')->nullable();
            $table->date('range_to')->nullable();

            $table->json('dimensions');          // list<string> field keys
            $table->json('metrics');             // list<string> field keys
            $table->json('filters')->nullable(); // {match, conditions:[…]}
            $table->json('sort')->nullable();    // {field, direction}

            $table->unsignedInteger('row_limit')->default(100);
            $table->string('chart_type', 16)->default('table');
            $table->boolean('is_favourite')->default(false);

            $table->timestamps();

            $table->index(['customer_id', 'is_favourite']);
            $table->index(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
