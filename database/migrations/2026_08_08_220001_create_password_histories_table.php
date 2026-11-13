<?php

declare(strict_types=1);

use App\Listeners\Security\RecordPasswordChange;
use App\Rules\PasswordPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password reuse prevention (spec §3.25.15 F-3.25.15-003, WP-22).
 *
 * One row per historical password hash, polymorphic over the two
 * authenticatables (users + customers). Written by
 * {@see RecordPasswordChange}; read by
 * {@see PasswordPolicy}. Pruned to the reuse-prevention depth on
 * write, so the table never grows unbounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('authenticatable_type');
            $table->unsignedBigInteger('authenticatable_id');
            $table->string('password_hash');
            $table->timestamp('created_at')->nullable();

            $table->index(
                ['authenticatable_type', 'authenticatable_id', 'created_at'],
                'password_histories_owner_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};
