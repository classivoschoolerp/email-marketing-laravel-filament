<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin user groups (spec §3.3): each group owns a spatie role (guard admin,
 * scope global) that carries the fine-grained view/create/update/delete
 * permission matrix over the admin modules. Assigning a user to a group
 * attaches the group role in model_has_roles (WP-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_groups');
    }
};
