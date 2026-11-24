<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The §1.8 F-1.8-006 password gate.
 *
 * Deliberately a single authority, like {@see \App\Services\Cms\CmsContent}'s
 * host fence: `show`, `thanks` AND `submit` all ask the same question, so a
 * protected page cannot be read through its thank-you URL or harvested by
 * POSTing straight at the submit endpoint.
 *
 * The unlock token stored in the session is a fingerprint OF THE HASH, not a
 * boolean. Changing (or clearing and re-setting) the password therefore
 * invalidates every existing unlock without touching sessions — which is the
 * only behaviour that makes "rotate the password" mean anything.
 *
 * The session is per-host by default (`SESSION_DOMAIN=null`), so a page served
 * on a customer's own domain gates on that host's own session. That is why the
 * unlock form keeps CSRF: unlike the CSRF-exempt submit endpoint, this gate is
 * session-backed by definition — if the session does not work, the gate cannot
 * work either, and silently disabling the check would be worse than a 419.
 */
class LandingPageAccess
{
    public function sessionKey(LandingPage $page): string
    {
        return 'landing.unlocked.'.$page->uid;
    }

    /** True when the page is public, or when this session has unlocked it. */
    public function unlocked(LandingPage $page, Request $request): bool
    {
        if (! $page->isPasswordProtected()) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        return hash_equals(
            $this->fingerprint($page),
            (string) $request->session()->get($this->sessionKey($page), ''),
        );
    }

    /** Verify a submitted password and, on success, unlock this session. */
    public function attempt(LandingPage $page, Request $request, string $password): bool
    {
        if (! $page->isPasswordProtected()) {
            return true;
        }

        if ($password === '' || ! Hash::check($password, (string) $page->access_password)) {
            return false;
        }

        $request->session()->put($this->sessionKey($page), $this->fingerprint($page));

        return true;
    }

    /** Bound to the stored hash, so rotating the password revokes access. */
    private function fingerprint(LandingPage $page): string
    {
        return substr(hash('sha256', (string) $page->access_password), 0, 32);
    }
}
