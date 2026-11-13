<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleScope;
use App\Support\Roles\RoleChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role as SpatieRole;

use function Illuminate\Support\enum_value;

/**
 * Three-tier role (spec §0.3, D-06). spatie/laravel-permission remains the
 * storage/enforcement engine; this model adds scope (system|global|customer),
 * tenant ownership, the locked flag for built-in roles, and soft-disabling
 * that preserves assignments (F-0.3-010).
 */
class Role extends SpatieRole
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'locked' => false,
    ];

    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'locked' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $role): void {
            // Tenant-owned roles are customer-scoped by definition; otherwise
            // an unspecified scope means an operator-defined global role.
            if ($role->customer_id !== null && $role->scope !== RoleScope::System) {
                $role->scope = RoleScope::Customer;
            }

            $role->scope ??= RoleScope::Global;
        });

        // Keep the per-request enforcement memo honest when roles change
        // (e.g. disable()/enable() mid-request).
        static::saved(fn () => app(RoleChecker::class)->flush());
        static::deleted(fn () => app(RoleChecker::class)->flush());
    }

    /**
     * Same contract as the parent, but uniqueness is checked per tenant so two
     * customers may define a role with the same name (F-0.3-009). NULL
     * customer_id duplicates are blocked here because SQL unique indexes treat
     * NULLs as distinct.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes = [])
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);
        $attributes['name'] = enum_value($attributes['name']);

        $query = static::query()
            ->where('name', $attributes['name'])
            ->where('guard_name', $attributes['guard_name']);

        isset($attributes['customer_id'])
            ? $query->where('customer_id', $attributes['customer_id'])
            : $query->whereNull('customer_id');

        if ($query->exists()) {
            throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        return static::query()->create($attributes);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Soft-disable: the role stops granting anything but every assignment in
     * model_has_roles is preserved, so re-enabling restores access (F-0.3-010).
     */
    public function disable(): void
    {
        $this->forceFill(['disabled_at' => now()])->save();
    }

    public function enable(): void
    {
        $this->forceFill(['disabled_at' => null])->save();
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    public function scopeOfScope(Builder $query, RoleScope $scope): Builder
    {
        return $query->where('scope', $scope->value);
    }

    public function scopeForCustomer(Builder $query, Customer|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof Customer ? $customer->getKey() : $customer);
    }
}
