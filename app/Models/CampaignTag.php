<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Free-form campaign tag (spec §2.3.2 F-2.3.2-004, §2.3.10 F-2.3.10-002).
 * Tenant-scoped so autocomplete only ever offers the customer's own tags.
 */
class CampaignTag extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_campaign_tag');
    }

    /** Case/space-folded uniqueness key within a tenant. */
    public static function slugFor(string $name): string
    {
        return Str::slug(mb_substr(trim($name), 0, 64)) ?: mb_strtolower(trim($name));
    }
}
