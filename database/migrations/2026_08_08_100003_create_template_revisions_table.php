<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Builder revision history (spec §2.5.4 F-2.5.4-008). A row per content save;
 * pruned to the most recent config('emp-templates.revisions_keep') rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_template_id')->constrained('email_templates')->cascadeOnDelete();
            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['email_template_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_revisions');
    }
};
