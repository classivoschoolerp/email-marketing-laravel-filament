<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §3.23 F-3.23-004 country row. Install-wide reference data — deliberately
 * NOT tenant-scoped.
 */
class Country extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }
}
