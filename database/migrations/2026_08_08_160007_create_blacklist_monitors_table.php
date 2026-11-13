<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — §3.18 blacklist monitors: admin-defined conditions (email and/or
 * reason regex) whose matches are automatically REMOVED from the global
 * blacklist on every scheduled run (blacklist-monitors:run, D-40) or
 * run-now. Either pattern may be null (= match everything on that axis);
 * both null is rejected at the form level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_monitors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email_pattern')->nullable();
            $table->string('reason_pattern')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('removals_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_monitors');
    }
};
