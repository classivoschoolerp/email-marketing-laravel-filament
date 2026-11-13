<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-2.15.4-002 — one explicit per-member permission grant or deny, layered
 * over whatever the assigned per-tenant Role gives (WP-39). A deny row wins
 * over the role so an owner can carve a single capability out of a shared
 * role without cloning it.
 */
class SubUserPermission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
        ];
    }

    public function subUser(): BelongsTo
    {
        return $this->belongsTo(SubUser::class);
    }
}
