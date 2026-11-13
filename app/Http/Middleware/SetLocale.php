<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\EmailList;
use App\Services\I18n\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Locale resolution for every rendered area (spec §3.22 F-3.22-002 /
 * §6.5 F-6.5-005, WP-20), most specific wins:
 *
 *   1. authenticated admin user  → users.locale
 *   2. authenticated customer    → customers.locale (per-tenant override)
 *   3. public list pages         → the list's default_language (WP-06
 *      advanced tab), resolved from the {list_uid} route parameter
 *   4. installation default      → CommonSettings::default_language
 *
 * A candidate only wins if the pack is usable (enabled row / lang dir —
 * see Locales::isUsable); otherwise resolution falls through. Attached to
 * the `web` group (public + auth module + Livewire routes) and to both
 * Filament panels' auth stacks by I18nServiceProvider. Idempotent.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->candidates($request) as $candidate) {
            if (is_string($candidate) && $candidate !== '' && Locales::isUsable($candidate)) {
                app()->setLocale($candidate);

                break;
            }
        }

        return $next($request);
    }

    /** @return list<string|null> ordered locale candidates */
    private function candidates(Request $request): array
    {
        return [
            auth('admin')->user()?->locale,
            auth('customer')->user()?->locale,
            $this->listLocale($request),
            Locales::defaultCode(),
        ];
    }

    /** The subscriber list's default language on public list pages (§1.5). */
    private function listLocale(Request $request): ?string
    {
        $listUid = $request->route('list_uid');

        if (! is_string($listUid) || $listUid === '') {
            return null;
        }

        try {
            $locale = EmailList::withoutTenancy()->where('uid', $listUid)->value('default_language');

            return is_string($locale) ? $locale : null;
        } catch (Throwable) {
            return null; // pre-migration / malformed uid
        }
    }
}
