<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\ComplianceServiceProvider;
use App\Services\Settings\SettingsReader;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Session lifetime + idle timeout (spec §3.25.15 F-3.25.15-002, WP-22).
 *
 * Registered on BOTH panels' auth middleware stacks by
 * {@see ComplianceServiceProvider} (the PanelRegistry
 * mutation pattern from AuthModuleServiceProvider/I18nServiceProvider, so
 * the WP-01 panel providers stay untouched).
 *
 * Two jobs on every authenticated request:
 *  - apply the configured session lifetime to the runtime session config, so
 *    the cookie/driver expiry follows the setting rather than the .env value;
 *  - log the session out when the gap since the previous request exceeded
 *    the idle timeout (0 = disabled, D-23), then redirect to the panel login.
 *
 * The idle marker lives in the session itself, so it survives across workers
 * and needs no extra storage.
 */
class EnforceIdleTimeout
{
    public const SESSION_KEY = 'emp_last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $security = SettingsReader::security();
            $lifetime = $security->sessionLifetimeMinutes();
            $idle = $security->idleTimeoutMinutes();
        } catch (Throwable) {
            // Settings not migrated yet (installer / first boot): never lock anyone out.
            return $next($request);
        }

        // F-3.25.15-002 (a): session lifetime drives the framework at runtime.
        config(['session.lifetime' => $lifetime]);

        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = time();
        $last = $session->get(self::SESSION_KEY);

        // F-3.25.15-002 (b): idle timeout.
        if ($idle > 0 && is_int($last) && ($now - $last) > ($idle * 60) && $this->isAuthenticated()) {
            $this->logout($request);

            return $this->timedOutResponse($request);
        }

        $session->put(self::SESSION_KEY, $now);

        return $next($request);
    }

    private function isAuthenticated(): bool
    {
        foreach (['admin', 'customer', 'web'] as $guard) {
            if (Auth::guard($guard)->check()) {
                return true;
            }
        }

        return false;
    }

    private function logout(Request $request): void
    {
        foreach (['admin', 'customer', 'web'] as $guard) {
            if (Auth::guard($guard)->check()) {
                Auth::guard($guard)->logout();
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function timedOutResponse(Request $request): Response
    {
        $message = __('settings.security.idle_timeout_message');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
        }

        $login = $request->is('admin', 'admin/*') ? '/admin/login' : '/customer/login';

        return redirect()->to($login)->with('status', $message);
    }
}
