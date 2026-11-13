<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One brand served from this installation (spec §6.4 F-6.4-001 … 004).
 *
 * Install-wide operator data — see the migration docblock for why this is
 * deliberately outside the BelongsToCustomer global scope.
 */
class Domain extends Model
{
    use HasFactory;

    public const SSL_UNKNOWN = 'unknown';

    public const SSL_NONE = 'none';

    public const SSL_ACTIVE = 'active';

    public const SSL_EXPIRING = 'expiring';

    public const SSL_EXPIRED = 'expired';

    public const SSL_MISMATCH = 'mismatch';

    public const SSL_ERROR = 'error';

    /** @var list<string> */
    public const SSL_STATUSES = [
        self::SSL_UNKNOWN,
        self::SSL_NONE,
        self::SSL_ACTIVE,
        self::SSL_EXPIRING,
        self::SSL_EXPIRED,
        self::SSL_MISMATCH,
        self::SSL_ERROR,
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
        'is_default' => 'boolean',
        'registration_enabled' => 'boolean',
        'login_enabled' => 'boolean',
        // §4.7 F-4.7-002 (WP-46).
        'panel_enabled' => 'boolean',
        'panel_restricted' => 'boolean',
        'force_https' => 'boolean',
        'email_templates' => 'array',
        'ssl_expires_at' => 'datetime',
        'ssl_checked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $domain): void {
            $domain->uid ??= (string) Str::uuid();
            $domain->host = self::normalizeHost((string) $domain->host);
        });

        static::updating(function (self $domain): void {
            if ($domain->isDirty('host')) {
                $domain->host = self::normalizeHost((string) $domain->host);
            }
        });

        // Exactly one default brand. A partial unique index is spelled
        // differently on MySQL 8 and SQLite (D-03), so the invariant is held
        // here instead — demote every other row inside the same write.
        static::saved(function (self $domain): void {
            if (! $domain->is_default) {
                return;
            }

            DB::table('domains')
                ->where('id', '!=', $domain->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    /**
     * Strip scheme, port, userinfo, path and trailing dot; lower-case what is
     * left. Everything that compares a request host to a stored host goes
     * through this, so "HTTPS://Brand.test:8443/" and "brand.test" are the
     * same brand and can never both be inserted.
     */
    public static function normalizeHost(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        $value = ltrim($value, '/');
        $value = explode('/', $value)[0];

        if (str_contains($value, '@')) {
            $value = substr($value, (int) strrpos($value, '@') + 1);
        }

        // Strip the port, but leave a bracketed IPv6 literal intact.
        if (! str_starts_with($value, '[') && str_contains($value, ':')) {
            $value = explode(':', $value)[0];
        }

        return mb_strtolower(rtrim($value, '.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(CmsPage::class);
    }

    public function isServable(): bool
    {
        return (bool) $this->active;
    }

    /**
     * Per-domain system-email override for one SystemEmailRenderer key.
     *
     * @return array{subject: ?string, body: ?string}|null
     */
    public function emailTemplate(string $key): ?array
    {
        $row = ($this->email_templates ?? [])[$key] ?? null;

        if (! is_array($row)) {
            return null;
        }

        $subject = trim((string) ($row['subject'] ?? ''));
        $body = trim((string) ($row['body'] ?? ''));

        if ($subject === '' && $body === '') {
            return null;
        }

        return [
            'subject' => $subject === '' ? null : $subject,
            'body' => $body === '' ? null : $body,
        ];
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('active', true)->first();
    }
}
