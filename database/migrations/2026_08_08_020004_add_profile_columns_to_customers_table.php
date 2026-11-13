<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile/account columns (spec §2.15.1): avatar, date format, pending email
 * (re-verification flow) and company details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('locale');
            $table->string('date_format', 32)->default('Y-m-d')->after('avatar_path');
            $table->string('pending_email')->nullable()->after('email');
            $table->string('company_address')->nullable()->after('company');
            $table->string('company_city', 100)->nullable()->after('company_address');
            $table->string('company_zone', 100)->nullable()->after('company_city');
            $table->string('company_country', 2)->nullable()->after('company_zone');
            $table->string('company_zip', 32)->nullable()->after('company_country');
            $table->string('company_website')->nullable()->after('company_zip');
            $table->string('company_vat', 64)->nullable()->after('company_website');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'avatar_path', 'date_format', 'pending_email', 'company_address',
                'company_city', 'company_zone', 'company_country', 'company_zip',
                'company_website', 'company_vat',
            ]);
        });
    }
};
