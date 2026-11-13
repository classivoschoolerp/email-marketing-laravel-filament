<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Sso\OneTimeLoginTokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A one-time login token (spec §6.7 F-6.7-001).
 *
 * The row never holds the token itself — only its SHA-256. See the migration
 * docblock and {@see OneTimeLoginTokenService}.
 */
class LoginToken extends Model
{
    use HasFactory;

    public const GUARD_ADMIN = 'admin';

    public const GUARD_CUSTOMER = 'customer';

    /** @var list<string> */
    public const GUARDS = [self::GUARD_ADMIN, self::GUARD_CUSTOMER];

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            $token->uid ??= (string) Str::uuid();
        });
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function isSpent(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** Redeemable = never used AND not yet expired. Both, always. */
    public function isRedeemable(): bool
    {
        return ! $this->isSpent() && ! $this->isExpired();
    }

    public function scopeRedeemable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
