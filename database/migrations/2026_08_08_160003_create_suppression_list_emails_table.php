<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — member rows of a suppression list (spec §2.9.11). One address per
 * row, lower-cased on insert, unique per list. Domain-level rows
 * (`@example.com`, F-2.9.11-003) are P2 and intentionally not modelled yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppression_list_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('suppression_list_id')->constrained('suppression_lists')->cascadeOnDelete();
            $table->string('email');

            $table->timestamps();

            $table->unique(['suppression_list_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_list_emails');
    }
};
