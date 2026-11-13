<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-customer feature-permission override row (spec §3.4 F-3.4-009), beyond
 * the group matrix. Shares its key namespace with customer_group_permissions.
 *
 * Read path: QuotaService::allows() checks this row BEFORE the group matrix —
 * the same precedence RoleChecker applies to direct model permissions over
 * role-granted ones (direct assignment wins). These are quota-matrix feature
 * toggles, deliberately kept out of the spatie tables so tenant feature flags
 * never mix with admin RBAC rows; RoleChecker stays the authority for
 * role-based permissions while QuotaService is the authority for the §0.3
 * feature/limit matrix.
 */
class CustomerPermission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
