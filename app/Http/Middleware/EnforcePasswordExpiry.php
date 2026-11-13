<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Providers\ComplianceServiceProvider;
use App\Services\Security\PasswordExpiry;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Password expiry enforcement (spec §3.25.15 F-3.25.15-003, WP-22).
 *
 * Registered on both panels' auth middleware by
 * {@see ComplianceServiceProvider}. While
 * `password_expiry_days` is 0 the middleware is a no-op (D-23).
 *
 * When a signed-in account's password is older than the window, every panel
 * page except the one that changes the password (and logout) redirects to
 * that page. Admin users additionally get `force_password_reset` flipped so
 * the existing §3.2 Security page banner explains why.
 */
class EnforcePasswordExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $expiry = app(PasswordExpiry::class);

            if (! $expiry->enabled()) {
                return $next($request);
            }
        } catch (Throwable) {
            return $next($request);
        }

        [$user, $panel] = $this->signedInUser();

        if ($user === null || ! $expiry->isExpired($user)) {
            return $next($request);
        }

        // The admin Security page already renders a "you must change your
        // password" banner off this flag (F-3.2-004).
        if ($user instanceof User && ! $user->force_password_reset) {
            $user->forceFill(['force_password_reset' => true])->saveQuietly();
        }

        $target = $panel === 'admin' ? '/admin/security' : '/customer/profile';

        // Never redirect the change page onto itself, nor logout.
        if ($request->is(ltrim($target, '/'), $panel.'/logout', $panel.'/security', $panel.'/profile')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(
                ['message' => __('settings.security.password_expired')],
                Response::HTTP_FORBIDDEN,
            );
        }

        return redirect()->to($target)->with('status', __('settings.security.password_expired'));
    }

    /** @return array{0: ?Model, 1: string} [user, panel id] */
    private function signedInUser(): array
    {
        foreach (['admin', 'customer'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user instanceof Model) {
                return [$user, $guard];
            }
        }

        return [null, 'customer'];
    }
}
