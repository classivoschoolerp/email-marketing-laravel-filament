<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LoginLogResource\Widgets;

use App\Enums\LoginResult;
use App\Models\LoginLog;
use App\Services\Auth\LoginThrottle;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Live brute-force lockouts (spec §3.27 F-3.27-006, WP-17). WP-02's
 * LoginThrottle stores lockout state in the cache-backed RateLimiter (D-04)
 * — there is no persistent IP-ban table by design (bans auto-expire). This
 * widget derives candidate IPs from recent login_logs failures and asks the
 * live limiter which of them are currently banned — honest, real-time state.
 */
class CurrentLockoutsWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.current-lockouts';

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{ip: string, attempts: int, available_in: int, last_attempt: ?Carbon}>
     */
    public function lockouts(): array
    {
        $throttle = app(LoginThrottle::class);

        return LoginLog::query()
            ->where('result', '!=', LoginResult::Success->value)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('ip')
            ->selectRaw('ip, count(*) as failures, max(created_at) as last_attempt')
            ->groupBy('ip')
            ->orderByDesc('failures')
            ->limit(100)
            ->get()
            ->filter(fn ($row): bool => $throttle->ipLockedOut((string) $row->ip))
            ->map(fn ($row): array => [
                'ip' => (string) $row->ip,
                'attempts' => $throttle->ipAttempts((string) $row->ip),
                'available_in' => $throttle->ipAvailableIn((string) $row->ip),
                'last_attempt' => $row->last_attempt !== null ? Carbon::parse((string) $row->last_attempt) : null,
            ])
            ->values()
            ->all();
    }
}
