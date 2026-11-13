<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Values captured for admin-defined extra registration fields (spec §1.2
 * F-1.2-002). Field definitions live in config('emp.registration.custom_fields')
 * until the admin settings UI ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_field_values');
    }
};
