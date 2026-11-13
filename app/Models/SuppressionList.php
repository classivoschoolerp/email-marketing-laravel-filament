<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Named suppression list (§2.9.11, WP-16). Attached to campaigns through
 * campaigns.suppression_list_ids (json, WP-11) and subtracted from the
 * audience in CampaignRecipients. `emails_count` is refreshed by
 * SuppressionListEmails after every mutation (F-2.9.11-005).
 */
class SuppressionList extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    public function emails(): HasMany
    {
        return $this->hasMany(SuppressionListEmail::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $list): void {
            $list->uid ??= (string) Str::uuid();
        });
    }
}
