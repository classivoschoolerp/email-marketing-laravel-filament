<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer key-value preferences (WP-10; first key:
 * "templates.default_builder", spec §2.5.3 F-2.5.3-005). The customers table
 * belongs to WP-01/02 and is not altered — preferences live here instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preferences');
    }
};
