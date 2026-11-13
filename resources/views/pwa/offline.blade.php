{{-- §4.8 F-4.8-002 offline shell (WP-40).

     This is the ONLY document the service worker pre-caches, and it is
     deliberately inert: no account data, no links into the panels, no
     inline JSON. Everything it needs is in this file, so it renders from
     cache with zero network. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('analytics.pwa.offline_title') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f8fafc;
            color: #0f172a;
            padding: 1.5rem;
        }
        .card {
            max-width: 26rem;
            width: 100%;
            text-align: center;
            background: #fff;
            border-radius: 0.9rem;
            padding: 2rem 1.5rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .1);
        }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1.25rem; font-size: .9rem; line-height: 1.5; color: #475569; }
        button {
            border: 0;
            border-radius: .55rem;
            background: #0f172a;
            color: #fff;
            padding: .6rem 1.4rem;
            font-size: .9rem;
            cursor: pointer;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            .card { background: #1e293b; box-shadow: none; }
            p { color: #94a3b8; }
            button { background: #e2e8f0; color: #0f172a; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('analytics.pwa.offline_title') }}</h1>
        <p>{{ __('analytics.pwa.offline_body') }}</p>
        <button type="button" onclick="window.location.reload()">{{ __('analytics.pwa.offline_retry') }}</button>
    </div>
</body>
</html>
