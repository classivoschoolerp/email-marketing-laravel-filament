<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('upgrade.mail.greeting') }}</title>
</head>
<body style="font-family: ui-sans-serif, system-ui, sans-serif; color: #111827;">
    <h1 style="font-size: 18px;">{{ __('upgrade.mail.greeting') }}</h1>

    <p>
        {{ __('upgrade.mail.line_version', [
            'version' => $release['version'] ?? '?',
            'current' => $currentVersion,
        ]) }}
    </p>

    @if (! empty($release['released_at']))
        <p>{{ __('upgrade.mail.line_released', ['date' => $release['released_at']]) }}</p>
    @endif

    @if (! empty($release['changelog']))
        <h2 style="font-size: 15px;">{{ __('upgrade.mail.line_changelog') }}</h2>
        <pre style="white-space: pre-wrap; font-family: ui-monospace, monospace; font-size: 13px; background: #f3f4f6; padding: 12px; border-radius: 6px;">{{ $release['changelog'] }}</pre>
    @endif

    <p>
        {{ $installable ? __('upgrade.mail.line_action') : __('upgrade.mail.line_manual') }}
    </p>

    <p>
        <a href="{{ rtrim((string) config('app.url'), '/') }}/admin/system/upgrades">
            {{ __('upgrade.mail.action') }}
        </a>
    </p>
</body>
</html>
