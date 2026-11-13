<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginResult;
use App\Services\Geo\IpLocationResolver;
use App\Services\Settings\SettingsReader;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Throwable;

/**
 * Login attempt record for both guards (spec §1.3 F-1.3-005). Written by the
 * auth event listeners; read by the customer Security page (§2.15.2) and the
 * admin panel (WP-03).
 *
 * WP-24 SURGICAL EDIT (§3.23): `location` is now resolved at capture time by
 * App\Services\Geo\IpLocationResolver when a GeoIP provider is configured
 * (D-10). It stays null on the default install — the resolver's null-provider
 * fast path costs nothing — and a failing provider never blocks a login.
 */
class LoginLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'result' => LoginResult::class,
            'created_at' => 'datetime',
        ];
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(
        string $guard,
        ?Authenticatable $user,
        ?string $email,
        LoginResult $result,
        ?Request $request = null,
    ): self {
        $request ??= request();
        $ip = $request?->ip();

        return static::query()->create([
            'guard' => $guard,
            'model_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'model_id' => $user?->getAuthIdentifier(),
            'email' => $email,
            // WP-22 SURGICAL EDIT (§3.25.16 F-3.25.16-005): the audit trail
            // records the IP through the anonymiser, so the toggle applies at
            // capture time. Geo resolution below still sees the full address.
            'ip' => IpAnonymizer::maybe($ip),
            'user_agent' => $request?->userAgent(),
            'location' => self::resolveLocation($ip),
            'result' => $result,
            'created_at' => now(),
        ]);
    }

    /** WP-24 (§3.23): GeoIP label, or null when no provider is configured. */
    private static function resolveLocation(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        try {
            if (! SettingsReader::location()->enrichLoginLogs()) {
                return null;
            }

            $label = app(IpLocationResolver::class)->locate($ip)?->label();
        } catch (Throwable) {
            return null;
        }

        return $label === null || $label === '' ? null : $label;
    }
}
