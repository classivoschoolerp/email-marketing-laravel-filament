<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('ops.health.alert_title', ['metric' => __('ops.health.metrics.'.$check['key'])]) }}</title>
</head>
<body style="font-family: ui-sans-serif, system-ui, sans-serif; color: #111827;">
    <h1 style="font-size: 18px;">
        {{ __('ops.health.alert_title', ['metric' => __('ops.health.metrics.'.$check['key'])]) }}
    </h1>

    <p>{{ __('ops.health.alert_intro', ['app' => $appName]) }}</p>

    <ul>
        <li>{{ __('ops.health.alert_current', ['value' => $check['display']]) }}</li>
        <li>{{ __('ops.health.alert_threshold', ['threshold' => $check['threshold']]) }}</li>
        <li>{{ $check['detail'] }}</li>
    </ul>

    <p>{{ __('ops.health.alert_footer') }}</p>
</body>
</html>
