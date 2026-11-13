@props(['title' => null, 'wide' => false])
<!DOCTYPE html>
{{-- dir per the active language pack's RTL flag (F-3.22-003, WP-20). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Services\I18n\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <a href="{{ url('/') }}" class="mb-6 text-2xl font-bold tracking-tight text-emerald-600">
            {{ config('app.name') }}
        </a>

        <div class="w-full {{ $wide ? 'max-w-2xl' : 'max-w-md' }} rounded-xl bg-white p-8 shadow">
            {{ $slot }}
        </div>
    </div>
    @stack('scripts')
</body>
</html>
