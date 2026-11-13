<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BounceType;
use Database\Factories\BounceRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One bounce classification rule (spec §2.9.4 F-2.9.4-006): a delimiter-less
 * regex pattern mapping to hard|soft|internal. Evaluated by BounceClassifier
 * in sort_order; the default set is seeded by the bounce_rules migration.
 * Global — not tenant-scoped.
 */
class BounceRule extends Model
{
    /** @use HasFactory<BounceRuleFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => BounceType::class,
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
