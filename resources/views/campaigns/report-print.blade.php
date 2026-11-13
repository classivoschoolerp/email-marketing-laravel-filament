{{-- §2.3.8 F-2.3.8-020 — print / PDF report (browser print path, no PDF lib). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('reports.print.title', ['name' => $campaign->name]) }}</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: -apple-system, Segoe UI, Arial, sans-serif; color: #111827; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 24px 0 8px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
        .meta { color: #6b7280; font-size: 12px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        th { color: #6b7280; font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: .05em; }
        .tiles { display: flex; flex-wrap: wrap; gap: 8px; }
        .tile { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 12px; min-width: 110px; }
        .tile .k { font-size: 10px; text-transform: uppercase; color: #6b7280; letter-spacing: .05em; }
        .tile .v { font-size: 18px; font-weight: 700; }
        .hint { font-size: 11px; color: #9ca3af; margin-top: 24px; }
        @media print { .hint, .noprint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <h1>{{ $campaign->name }}</h1>
    <div class="meta">
        {{ $campaign->subject }}<br>
        {{ __('reports.print.generated_at', ['date' => now()->toDayDateTimeString()]) }}
    </div>

    <h2>{{ __('reports.page.title', ['name' => '']) }}</h2>
    <div class="tiles">
        @foreach (['recipients', 'delivered', 'bounced', 'unique_opens', 'open_rate', 'unique_clicks', 'click_rate', 'ctor', 'unsubscribes', 'complaints', 'forwards'] as $key)
            <div class="tile">
                <div class="k">{{ __('reports.stats.'.$key) }}</div>
                <div class="v">{{ is_float($stats[$key]) ? $stats[$key].'%' : number_format((int) $stats[$key]) }}</div>
            </div>
        @endforeach
    </div>

    @if ($benchmarks['average'] !== null)
        <h2>{{ __('reports.comparison.title') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('reports.comparison.metric') }}</th>
                    <th>{{ __('reports.comparison.this_campaign') }}</th>
                    <th>{{ __('reports.comparison.account_average') }}</th>
                    @if ($benchmarks['previous'])
                        <th>{{ $benchmarks['previous']['name'] }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach (['open_rate', 'click_rate', 'ctor', 'unsubscribes', 'bounced'] as $metric)
                    <tr>
                        <td>{{ __('reports.stats.'.$metric) }}</td>
                        <td>{{ $stats[$metric] }}</td>
                        <td>{{ $benchmarks['average'][$metric] }}</td>
                        @if ($benchmarks['previous'])
                            <td>{{ $benchmarks['previous']['stats'][$metric] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="hint">{{ __('reports.comparison.basis', ['count' => $benchmarks['average_basis']]) }}</div>
    @endif

    @if ($topLinks !== [])
        <h2>{{ __('reports.tabs.top_links') }}</h2>
        <table>
            <thead><tr><th>{{ __('reports.columns.url') }}</th><th>{{ __('reports.columns.clicks') }}</th><th>%</th></tr></thead>
            <tbody>
                @foreach ($topLinks as $link)
                    <tr><td>{{ $link['url'] }}</td><td>{{ $link['clicks'] }}</td><td>{{ $link['percent'] }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($devices !== [])
        <h2>{{ __('reports.tabs.devices') }}</h2>
        <table>
            <thead><tr><th>{{ __('reports.columns.device') }}</th><th>{{ __('reports.columns.count') }}</th><th>%</th></tr></thead>
            <tbody>
                @foreach (array_merge($devices, $clients) as $row)
                    <tr><td>{{ $row['label'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['percent'] }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($geo !== [])
        <h2>{{ __('reports.tabs.geo') }}</h2>
        <table>
            <thead><tr><th>{{ __('reports.columns.country') }}</th><th>{{ __('reports.columns.count') }}</th><th>%</th></tr></thead>
            <tbody>
                @foreach ($geo as $row)
                    <tr><td>{{ $row['country'] ?? '—' }}{{ $row['city'] ? ' · '.$row['city'] : '' }}</td><td>{{ $row['count'] }}</td><td>{{ $row['percent'] }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="hint">{{ __('reports.print.hint') }}</p>
</body>
</html>
