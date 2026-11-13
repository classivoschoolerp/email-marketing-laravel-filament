<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → customer announcements (spec §2.1 F-2.1-010, WP-18): operator-
 * authored notices rendered on the customer dashboard while active and
 * inside the optional starts_at/ends_at window. Dismissals are per customer
 * (announcement_dismissals) so a notice disappears only for whoever closed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('level', 16)->default('info'); // info | warning | danger
            $table->boolean('active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('dismissible')->default(true);
            $table->timestamps();

            $table->index(['active', 'starts_at', 'ends_at']);
        });

        Schema::create('announcement_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['announcement_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcements');
    }
};
