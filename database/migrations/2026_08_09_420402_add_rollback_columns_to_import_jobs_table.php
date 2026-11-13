<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — F-2.2.7-013 rollback bookkeeping on the import history row.
 *
 * `rolled_back_at` stamps the run once RollbackImportJob finished, which is
 * what hides the rollback action and shows the "Rolled back" badge in the
 * history table; `rolled_back_count` is how many subscriber rows the
 * rollback touched (deleted + reverted), reported back to the operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->timestamp('rolled_back_at')->nullable()->after('finished_at');
            $table->unsignedInteger('rolled_back_count')->nullable()->after('rolled_back_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            $table->dropColumn(['rolled_back_at', 'rolled_back_count']);
        });
    }
};
