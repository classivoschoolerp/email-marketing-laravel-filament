<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined extra registration fields (spec §1.2 F-1.2-002). The public
 * registration form reads active rows ordered by sort_order; when the table
 * is empty the config('emp.registration.custom_fields') stub remains the
 * fallback so WP-02 behaviour is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label');
            $table->string('type', 16)->default('text');
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_fields');
    }
};
