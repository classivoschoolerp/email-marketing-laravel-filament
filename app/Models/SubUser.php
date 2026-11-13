<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubUserStatus;
use Database\Factories\SubUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * F-0.2-004 / §2.15.4 — a team member under one customer (WP-39).
 *
 * NOT an Authenticatable. A sub-user's credentials are verified by
 * App\Auth\CustomerUserProvider, which then hands the customer guard the
 * PARENT Customer — so `Filament::auth()->user()` is always the tenant and
 * the D-05 global scope keeps working untouched. The sub-user identity for
 * the request lives in App\Support\Team\SubUserContext.
 *
 * That shape is what makes cross-tenant leakage structurally impossible:
 * there is no code path where a sub-user session carries a customer_id
 * other than its own `customer_id`.
 *
 * Roles: spatie HasRoles on the `customer` guard, so a per-tenant Role
 * (roles.scope = customer, D-06/D-17) can be assigned and resolved by
 * RoleChecker with no changes (D-19).
 */
class SubUser extends Model
{
    /** @use HasFactory<SubUserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /** Days an invitation link stays valid. */
    public const INVITATION_TTL_DAYS = 14;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'invitation_token'];

    /** spatie: team members carry customer-guard roles/permissions. */
    protected string $guard_name = 'customer';

    protected function casts(): array
    {
        return [
            'status' => SubUserStatus::class,
            'password' => 'hashed',
            'invitation_sent_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_sent_count' => 'integer',
            'accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $subUser): void {
            $subUser->uid ??= (string) Str::uuid();
            $subUser->email = mb_strtolower(trim((string) $subUser->email));
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(SubUserPermission::class);
    }

    public function listAccess(): HasMany
    {
        return $this->hasMany(SubUserListAccess::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'actor_id')
            ->where('actor_type', self::class);
    }

    public function fullName(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : (string) $this->email;
    }

    public function isPending(): bool
    {
        return $this->status === SubUserStatus::Pending;
    }

    public function canLogin(): bool
    {
        return $this->status->canLogin()
            && $this->password !== null
            && $this->deleted_at === null;
    }

    /** F-2.15.4-004: is the outstanding invitation still usable? */
    public function invitationIsValid(): bool
    {
        return $this->invitation_token !== null
            && $this->invitation_expires_at !== null
            && $this->invitation_expires_at->isFuture();
    }
}
