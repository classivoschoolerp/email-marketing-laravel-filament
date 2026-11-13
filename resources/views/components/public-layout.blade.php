@props(['title' => null, 'customCss' => null, 'customJs' => null, 'footerHtml' => ''])
<!DOCTYPE html>
{{-- dir per the active language pack's RTL flag (F-3.22-003, WP-20). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Services\I18n\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? \App\Services\Settings\SettingsReader::common()->siteName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if ($customCss !== null)
        {{-- Per-page custom CSS (F-1.5-017); closing-tag sequences stripped. --}}
        <style>{!! $customCss !!}</style>
    @endif
    @stack('head')
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
    <div class="flex min-h-screen flex-col items-center px-4 py-10">
        <a href="{{ url('/') }}" class="mb-6 text-2xl font-bold tracking-tight text-emerald-600">
            {{ \App\Services\Settings\SettingsReader::common()->siteName() }}
        </a>

        <div class="w-full max-w-2xl rounded-xl bg-white p-8 shadow">
            {{ $slot }}
        </div>

        @if (trim($footerHtml) !== '')
            {{-- CAN-SPAM/GDPR company footer, auto-injected (F-1.5-014). --}}
            <div class="mt-6 w-full max-w-2xl text-center text-xs text-gray-500 [&_p]:mb-1">
                {!! $footerHtml !!}
            </div>
        @endif
    </div>

    {{-- WP-22 (§3.25.16 F-3.25.16-001 / §4.5 F-4.5-006): cookie consent
         banner on public pages only. Renders nothing while the setting is
         off or the visitor has already chosen. --}}
    @include('public.partials.cookie-consent')

    @stack('scripts')
    @if ($customJs !== null)
        {{-- Per-page custom JS (F-1.5-017); closing-tag sequences stripped. --}}
        <script>{!! $customJs !!}</script>
    @endif
</body>
</html>
