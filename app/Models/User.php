<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Roles\RoleChecker;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * System administrator account (spec §3.2) — authenticates on the `admin`
 * guard for the /admin panel. Customers are a separate model/guard (§0.2).
 *
 * WP-03: adds the spatie HasRoles trait (permitted by D-19 — RoleChecker
 * remains the enforcement read path), the user-group link (§3.3), account
 * status, timezone/avatar, TOTP 2FA columns (D-35) and the
 * force-password-reset flag (F-3.2-004).
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasRoles;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DISABLED];

    /** spatie guard resolution — admin permissions live on the admin guard. */
    protected string $guard_name = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'user_group_id',
        'status',
        'timezone',
        'locale',
        'avatar_path',
        'force_password_reset',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'twofa_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'twofa_secret' => 'encrypted',
            'twofa_enabled' => 'boolean',
            'force_password_reset' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }

    public function loginLogs(): MorphMany
    {
        return $this->morphMany(LoginLog::class, 'model');
    }

    /** Impersonation sessions this admin performed (F-3.4-006 audit). */
    public function impersonations(): HasMany
    {
        return $this->hasMany(ImpersonationLog::class, 'admin_id');
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserRecoveryCode::class);
    }

    public function isDisabled(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE;
    }

    /**
     * spatie's PermissionServiceProvider registers a Gate::before that calls
     * this method once the HasRoles trait is present. Route it through
     * RoleChecker so soft-disabled roles stop granting permissions
     * (F-0.3-010) — the exact behavior guarantee D-19 requires when adding
     * the trait.
     *
     * @param  string|int|Permission  $permission
     */
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        $name = match (true) {
            is_string($permission) => $permission,
            $permission instanceof Permission => (string) $permission->name,
            default => (string) \Spatie\Permission\Models\Permission::findById((int) $permission, $guardName ?? 'admin')->name,
        };

        return app(RoleChecker::class)->hasActivePermission($this, $name);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Disabled admins are locked out at route level (F-3.2-004).
        return $panel->getId() === 'admin' && ! $this->isDisabled();
    }
}
