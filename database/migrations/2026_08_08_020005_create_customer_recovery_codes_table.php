<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2FA recovery codes, hashed at rest (spec §2.15.2 F-2.15.2-001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_recovery_codes');
    }
};
