<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trackable URL inside a campaign (spec §1.7). WP-13's content rewriter
 * creates these rows; the public click endpoint resolves (campaign_uid, hash)
 * → url and 302-redirects. `hash` is UrlSigner's HMAC of `url` (F-1.7-008).
 * `campaign_id` is a plain nullable bigint until WP-11 creates campaigns.
 * Global — tracking rows are resolved by uid, never tenant-scoped.
 */
class CampaignTrackUrl extends Model
{
    protected $guarded = ['id'];

    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignTrackClick::class, 'url_id');
    }
}
