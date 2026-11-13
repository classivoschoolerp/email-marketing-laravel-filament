<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin account columns (spec §3.2): user group, status (enable/disable),
 * timezone, avatar, TOTP 2FA (encrypted secret — closes DECISIONS D-35 admin
 * 2FA gap) and the force-password-reset flag (F-3.2-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->nullable()->after('name')
                ->constrained('user_groups')->nullOnDelete();
            $table->string('status', 32)->default('active')->index()->after('password');
            $table->string('timezone', 64)->default('UTC')->after('status');
            $table->string('avatar_path')->nullable()->after('timezone');
            $table->text('twofa_secret')->nullable()->after('avatar_path');
            $table->boolean('twofa_enabled')->default(false)->after('twofa_secret');
            $table->boolean('force_password_reset')->default(false)->after('twofa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_group_id');
            $table->dropColumn([
                'status', 'timezone', 'avatar_path',
                'twofa_secret', 'twofa_enabled', 'force_password_reset',
            ]);
        });
    }
};
