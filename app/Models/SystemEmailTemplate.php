<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SystemEmails\SystemEmailRenderer;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin override row for one §3.15 system email key (WP-20). Global —
 * system emails are installation-level, not tenant data. Resolution
 * (override vs translatable default) lives in
 * {@see SystemEmailRenderer}.
 */
class SystemEmailTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
