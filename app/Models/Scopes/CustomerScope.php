<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class CustomerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentCustomer::class);

        if ($current->check()) {
            $builder->where($model->qualifyColumn('customer_id'), $current->id());
        }
    }
}
