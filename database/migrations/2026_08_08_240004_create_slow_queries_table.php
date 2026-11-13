<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.6 F-6.6-003 slow-query recorder (WP-24). Rows are written by
 * App\Support\Ops\SlowQueryRecorder from a DB::listen hook, and only while
 * the `ops.slow_query_logging` setting is on — the listener is not even
 * registered otherwise, so the default install pays nothing.
 *
 * `fingerprint` is a hash of the normalised SQL so repeated shapes aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slow_queries', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 32)->index();
            $table->text('sql');
            $table->unsignedInteger('time_ms')->index();
            $table->string('connection', 64)->nullable();
            $table->string('context', 191)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slow_queries');
    }
};
