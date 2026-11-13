<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal subscriber tag row (spec §0.5 subscriber_tags — built by WP-21 for
 * the F-2.4.2-007/025 tag trigger + action nodes). Attach/detach through
 * App\Services\Subscribers\SubscriberTagService so created/deleted model
 * events feed the automation hooks. Names normalized lowercase.
 */
class SubscriberTag extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $tag): void {
            $tag->name = self::normalize((string) $tag->name);
        });
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class)->withoutGlobalScopes();
    }
}
