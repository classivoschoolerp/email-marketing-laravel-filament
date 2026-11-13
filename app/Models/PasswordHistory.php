<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A previously used password hash (spec §3.25.15 F-3.25.15-003). Deliberately
 * NOT tenant-scoped: rows exist for both admin users and customers and are
 * only ever read by the password policy rule for one specific account.
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'password_histories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
