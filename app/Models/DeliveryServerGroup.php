<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryServerGroupType;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Scopes\CustomerScope;
use App\Support\Tenancy\CurrentCustomer;
use Database\Factories\DeliveryServerGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Delivery server group / pool (spec §3.13.1 F-3.13.1-008/009, WP-33).
 *
 * ServerPicker confines a pick to a pool's members; a customer's single
 * enabled default pool applies automatically when no explicit pool is given
 * (see ServerPicker::poolRestriction()). customer_id NULL = system pool.
 */
class DeliveryServerGroup extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<DeliveryServerGroupFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'type' => 'pool',
        'enabled' => true,
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => DeliveryServerGroupType::class,
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            $group->uid ??= (string) Str::uuid();
        });

        // Only one default pool per owner — assigning a new one demotes the
        // previous, so ServerPicker never has to choose between two.
        static::saved(function (self $group): void {
            if (! $group->is_default) {
                return;
            }

            static::query()
                ->withoutGlobalScope(CustomerScope::class)
                ->where('customer_id', $group->customer_id)
                ->whereKeyNot($group->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(
            DeliveryServer::class,
            'delivery_server_group_members',
            'delivery_server_group_id',
            'delivery_server_id',
        )->withoutGlobalScopes()->withPivot('sort_order')->withTimestamps();
    }

    public function ips(): HasMany
    {
        return $this->hasMany(DeliveryServerIp::class);
    }

    public function isSystem(): bool
    {
        return $this->customer_id === null;
    }

    /** Own pools + system pools, mirroring DeliveryServer::scopeBrowsable(). */
    public function scopeBrowsable(Builder $query): Builder
    {
        $customer = app(CurrentCustomer::class)->get();

        $query->withoutGlobalScope(CustomerScope::class);

        if ($customer === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($customer): void {
            $q->where($this->qualifyColumn('customer_id'), $customer->id)
                ->orWhereNull($this->qualifyColumn('customer_id'));
        });
    }

    /** @return list<int> member delivery server ids */
    public function memberIds(): array
    {
        return $this->servers()->pluck('delivery_servers.id')->map('intval')->all();
    }
}
