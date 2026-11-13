<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-customer key-value preference row (WP-10). Read/written through
 * App\Services\Preferences\CustomerPreferences — first consumer is the
 * default-builder choice (spec §2.5.3 F-2.5.3-005).
 */
class CustomerPreference extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];
}
