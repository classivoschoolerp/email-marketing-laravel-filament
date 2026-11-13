@props(['title' => null, 'step' => 1])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Services\I18n\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('install.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
    <div class="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-4 py-10">
        <h1 class="mb-6 text-center text-2xl font-bold tracking-tight text-emerald-600">
            {{ __('install.title') }}
        </h1>

        <ol class="mb-6 flex flex-wrap justify-center gap-2 text-xs">
            @foreach (['requirements', 'database', 'admin', 'license', 'services'] as $index => $name)
                <li class="rounded-full px-3 py-1 {{ $step === $index + 1 ? 'bg-emerald-600 text-white' : ($step > $index + 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600') }}">
                    {{ $index + 1 }}. {{ __('install.steps.'.$name) }}
                </li>
            @endforeach
        </ol>

        <div class="rounded-xl bg-white p-8 shadow">
            @if ($errors->any())
                <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</body>
</html>
