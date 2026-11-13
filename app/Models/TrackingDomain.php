<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainStatus;
use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\TrackingDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tracking domain (spec §2.9.8, WP-15): a customer-owned (or global,
 * customer_id NULL — §3.13.6) CNAME pointing at the app host, used by
 * TrackingUrlBuilder to build open/click/unsubscribe URLs once verified.
 * The domain lives in `name` (WP-11's campaign select plucks that column).
 */
class TrackingDomain extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<TrackingDomainFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'verified' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $domain): void {
            $domain->name = mb_strtolower(trim((string) $domain->name));
        });
    }

    public function deliveryServers(): HasMany
    {
        return $this->hasMany(DeliveryServer::class)->withoutGlobalScopes();
    }

    public function isVerified(): bool
    {
        return $this->verified === true;
    }

    /** The host tracking domains must CNAME to (config override or app.url). */
    public static function cnameTarget(): string
    {
        $configured = config('emp-infra.tracking.cname_target');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }
}
