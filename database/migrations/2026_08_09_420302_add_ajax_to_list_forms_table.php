<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — F-2.2.11-006 AJAX submit option for the generated subscribe form.
 * When on, FormHtmlGenerator emits a fetch()-based submit handler and the
 * snippet renders the success/error message inline instead of navigating to
 * the F-2.2.11-005 redirect URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_forms', function (Blueprint $table): void {
            $table->boolean('ajax')->default(false)->after('styled');
        });
    }

    public function down(): void
    {
        Schema::table('list_forms', function (Blueprint $table): void {
            $table->dropColumn('ajax');
        });
    }
};
