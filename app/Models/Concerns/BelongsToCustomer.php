<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Customer;
use App\Models\Scopes\CustomerScope;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every tenant-owned model uses this trait (spec §0.2). Applies the global scope and
 * fills customer_id on create from the current tenant.
 *
 * @mixin Model
 */
trait BelongsToCustomer
{
    public static function bootBelongsToCustomer(): void
    {
        static::addGlobalScope(new CustomerScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('customer_id') === null) {
                $model->setAttribute('customer_id', app(CurrentCustomer::class)->id());
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Escape hatch for admin panel / cross-tenant system code. Use deliberately. */
    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope(CustomerScope::class);
    }
}
