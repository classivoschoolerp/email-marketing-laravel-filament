<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 — custom CSS/JS per list page (spec §1.5 F-1.5-017). Stored on the
 * per-list override row (list_pages) and injected into the public render
 * inside <style>/<script> blocks with closing-tag sequences stripped so the
 * content cannot break out of its context.
 *
 * Note: subscribers.unsubscribe_reason (F-1.5-007) already exists — it was
 * created with the WP-06 subscribers table — so no column is added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_pages', function (Blueprint $table): void {
            $table->text('custom_css')->nullable()->after('redirect_url');
            $table->text('custom_js')->nullable()->after('custom_css');
        });
    }

    public function down(): void
    {
        Schema::table('list_pages', function (Blueprint $table): void {
            $table->dropColumn(['custom_css', 'custom_js']);
        });
    }
};
