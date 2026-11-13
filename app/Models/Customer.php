<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerStatus;
use Database\Factories\CustomerFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Customer extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'twofa_secret'];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'password' => 'hashed',
            'twofa_secret' => 'encrypted',
            'twofa_enabled' => 'boolean',
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            // §2.15.2 F-2.15.2-004 — single IPs / CIDR ranges. NULL or []
            // means unrestricted (D-23).
            'ip_allowlist' => 'array',
            // §4.7 F-4.7-004 — agency mode.
            'brand_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $customer): void {
            $customer->uid ??= (string) Str::uuid();
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /** Per-customer quota overrides — read by QuotaService before the group (F-3.4-008). */
    public function limitOverrides(): HasMany
    {
        return $this->hasMany(CustomerLimit::class);
    }

    /** Per-customer feature-permission overrides (F-3.4-009). */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(CustomerPermission::class);
    }

    /** Internal admin notes (F-3.4-012). */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function loginLogs(): MorphMany
    {
        return $this->morphMany(LoginLog::class, 'model');
    }

    /** Times an admin impersonated this account (F-3.4-006 audit). */
    public function impersonationLogs(): HasMany
    {
        return $this->hasMany(ImpersonationLog::class);
    }

    public function lists(): HasMany
    {
        return $this->hasMany(EmailList::class);
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    public function getFilamentName(): string
    {
        return $this->full_name !== '' ? $this->full_name : $this->email;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'customer' && $this->status->canLogin();
    }
}
