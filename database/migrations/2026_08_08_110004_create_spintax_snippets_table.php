<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved Spintax snippet library (spec §2.3.3 F-2.3.3-023) — reusable
 * `{a|b|c}` fragments customers insert into campaign content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spintax_snippets', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->text('content');
            $table->timestamps();

            $table->index(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spintax_snippets');
    }
};
