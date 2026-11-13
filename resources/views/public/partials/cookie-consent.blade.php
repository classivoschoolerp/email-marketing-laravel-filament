{{-- WP-22 cookie consent banner (spec §3.25.16 F-3.25.16-001 / §4.5
     F-4.5-006). Rendered on PUBLIC pages only — the Filament panels own
     their own <html>. The banner is skipped entirely when the setting is
     off, and hides itself once a choice cookie exists.

     No JS framework and no external request: the choice POSTs to
     privacy.cookie_consent, which sets a first-party cookie. Reading the
     setting is guarded so a pre-migration installation renders nothing. --}}
@php
    try {
        $privacy = \App\Services\Settings\SettingsReader::privacy();
        $cookieBannerEnabled = $privacy->cookieConsentEnabled();
        $cookieBannerText = $cookieBannerEnabled ? $privacy->cookieConsentText() : null;
        $cookiePolicyUrl = $cookieBannerEnabled ? $privacy->cookiePolicyUrl() : null;
    } catch (\Throwable) {
        $cookieBannerEnabled = false;
        $cookieBannerText = null;
        $cookiePolicyUrl = null;
    }

    $cookieBannerName = (string) config('emp-privacy.cookies.cookie_name', 'emp_cookie_consent');
    $cookieBannerChoice = request()->cookie($cookieBannerName);
@endphp

@if ($cookieBannerEnabled && $cookieBannerChoice === null)
    <div id="emp-cookie-consent"
         role="region"
         aria-label="{{ __('privacy.cookies.aria_label') }}"
         class="fixed inset-x-0 bottom-0 z-50 border-t border-gray-200 bg-white/95 p-4 shadow-lg backdrop-blur">
        <div class="mx-auto flex max-w-3xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-700">
                {{ $cookieBannerText }}
                @if ($cookiePolicyUrl !== null)
                    <a href="{{ $cookiePolicyUrl }}" class="ml-1 font-medium text-emerald-700 underline">
                        {{ __('privacy.cookies.policy_link') }}
                    </a>
                @endif
            </p>

            <div class="flex shrink-0 gap-2">
                <form method="post" action="{{ route('privacy.cookie_consent') }}">
                    @csrf
                    <input type="hidden" name="choice" value="declined">
                    <button type="submit"
                            class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ __('privacy.cookies.decline') }}
                    </button>
                </form>
                <form method="post" action="{{ route('privacy.cookie_consent') }}">
                    @csrf
                    <input type="hidden" name="choice" value="accepted">
                    <button type="submit"
                            class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        {{ __('privacy.cookies.accept') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endif
