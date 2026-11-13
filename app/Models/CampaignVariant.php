<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One A/B test variant of a campaign (spec §2.3.6, WP-35).
 *
 * Not tenant-scoped in its own right: a variant is meaningless without its
 * campaign, and the campaign carries the BelongsToCustomer global scope — so
 * every read path reaches variants through `$campaign->variants`, which is
 * already fenced. Policies gate the UI on the parent campaign.
 *
 * A null override column means "inherit the campaign value" (see the
 * migration): a subject-line test stores subjects and nothing else.
 */
class CampaignVariant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'send_delay_minutes' => 'integer',
            'weight' => 'integer',
            'recipients' => 'integer',
            'is_winner' => 'boolean',
            // WP-46 §2.3.6 F-2.3.6-004 — the factorial level map, or null for
            // a variant that is not part of a multivariate design.
            'factors' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $variant): void {
            $variant->uid ??= (string) Str::uuid();
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recipientAssignments(): HasMany
    {
        return $this->hasMany(CampaignVariantRecipient::class);
    }

    /** Label used everywhere in the UI: "A", "B", … (F-2.3.6-002). */
    public static function labelForPosition(int $position): string
    {
        return chr(65 + max(0, min(25, $position)));
    }

    /**
     * The effective value of a variable for this variant: the override when
     * the variant sets one, the campaign's own value otherwise. Never returns
     * a half-applied mix — that is what makes a "subject only" test honest.
     */
    public function effective(Campaign $campaign, string $attribute): mixed
    {
        $own = $this->getAttribute($attribute);

        if ($own === null || (is_string($own) && trim($own) === '')) {
            return $campaign->getAttribute($attribute);
        }

        return $own;
    }
}
