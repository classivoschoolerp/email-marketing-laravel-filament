<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    /** @use HasFactory<CustomerGroupFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'default_delivery_servers' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Only one group may be the signup default (spec §3.5 F-3.5-001):
        // promoting a group demotes every other default atomically.
        static::saved(function (self $group): void {
            if ($group->is_default) {
                static::query()
                    ->whereKeyNot($group->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * Clone the group with its full limit + feature-permission matrices and
     * default delivery-server assignment (spec §3.5 F-3.5-017). The copy is
     * never the default group.
     */
    public function cloneGroup(?string $name = null): self
    {
        /** @var self $copy */
        $copy = static::query()->create([
            'name' => $name ?? $this->name.' (copy)',
            'description' => $this->description,
            'is_default' => false,
            'sort_order' => (int) $this->sort_order,
            'default_delivery_servers' => $this->default_delivery_servers,
        ]);

        foreach ($this->limits()->get() as $limit) {
            $copy->limits()->create(['key' => $limit->key, 'value' => $limit->value]);
        }

        foreach ($this->featurePermissions()->get() as $permission) {
            $copy->featurePermissions()->create(['key' => $permission->key, 'allowed' => $permission->allowed]);
        }

        return $copy;
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** Quota matrix rows (spec §0.3 F-0.3-005). */
    public function limits(): HasMany
    {
        return $this->hasMany(CustomerGroupLimit::class);
    }

    /** Feature-permission matrix rows (spec §0.3 F-0.3-004). */
    public function featurePermissions(): HasMany
    {
        return $this->hasMany(CustomerGroupPermission::class);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }
}
