<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template categories (spec §2.5.2, §3.14). customer_id NULL = system category
 * owned by the operator (admin gallery, §3.14); non-null = customer-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_categories');
    }
};
