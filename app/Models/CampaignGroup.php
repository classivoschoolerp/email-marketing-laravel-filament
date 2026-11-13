<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\CampaignGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Campaign group (spec §2.3.10 F-2.3.10-001). Tenant-scoped folder for
 * campaigns; deleting a group detaches campaigns (FK nullOnDelete).
 */
class CampaignGroup extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<CampaignGroupFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            $group->uid ??= (string) Str::uuid();
        });
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
