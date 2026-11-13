<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One globally blacklisted address or domain (§3.17, WP-16 full module on
 * the WP-09 forward-base). Exact rows keep the full address in `email`;
 * domain rows (`is_domain`) keep the bare lower-case domain and match every
 * address at it. `customer_id` nullable: null = install-wide entry; set =
 * originated from that customer (and, in per-customer isolation mode, only
 * applies to them — see BlacklistMatcher). Deliberately NOT tenant-scoped:
 * the shared/isolated read semantics live in BlacklistMatcher and the
 * Filament resources, never in a global scope.
 */
class GlobalBlacklistEmail extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BLOCK_REQUEST = 'block-request';

    public const SOURCE_IMPORT = 'import';

    /** Inserted by WP-15 processors — mirrored here for filter options. */
    public const SOURCE_BOUNCE = 'bounce';

    public const SOURCE_FBL = 'fbl';

    /** Auto-action inserts from email verification (§2.9.12). */
    public const SOURCE_VERIFICATION = 'verification';

    /** Inserted by WP-21 automation suppress/blacklist action nodes (§2.4.2). */
    public const SOURCE_AUTOMATION = 'automation';

    /** @return list<string> */
    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_BLOCK_REQUEST,
        self::SOURCE_IMPORT,
        self::SOURCE_BOUNCE,
        self::SOURCE_FBL,
        self::SOURCE_VERIFICATION,
        self::SOURCE_AUTOMATION,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_domain' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            // Domain rows store the bare domain — strip a pasted "@".
            $entry->email = ltrim(mb_strtolower(trim((string) $entry->email)), '@');
        });
    }
}
