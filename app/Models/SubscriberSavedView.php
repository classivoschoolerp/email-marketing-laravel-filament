<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;

/**
 * A named subscribers-index view (spec §2.2.4 F-2.2.4-009): the table's filter
 * state, its toggled column visibility and the search term. Tenant-scoped via
 * BelongsToCustomer (D-05) — a view is per-customer, never global.
 *
 * @property array<string, mixed>|null $filters
 * @property array<string, mixed>|null $columns
 */
class SubscriberSavedView extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
        ];
    }

    /** @return array<int, string> id => name, for the load/delete pickers. */
    public static function pickerOptions(): array
    {
        return self::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
