<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hashed 2FA recovery code for an admin user — mirrors CustomerRecoveryCode
 * (spec §3.2, D-35 admin 2FA).
 */
class UserRecoveryCode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
