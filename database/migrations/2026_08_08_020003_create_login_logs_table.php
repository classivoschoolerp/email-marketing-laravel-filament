<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login attempt log for both guards (spec §1.3 F-1.3-005, §0.5 login_logs).
 * `location` stays null until a GeoIP provider is configured (D-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('guard', 32)->index();
            $table->nullableMorphs('model');
            $table->string('email')->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->string('location')->nullable();
            $table->string('result', 32)->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
