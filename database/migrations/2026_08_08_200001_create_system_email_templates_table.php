<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-20 — admin overrides for the §3.15 system email templates. One row per
 * overridden key; absence of a row (or enabled=false, or an empty column)
 * falls back to the translatable code default in lang/{locale}/system-emails.php
 * — the same layering ListPageResolver uses for list pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            // Disabled = ignore the stored override and render the default
            // content — the email itself is still sent (§3.15 rows describe
            // editable content, not kill switches).
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_email_templates');
    }
};
