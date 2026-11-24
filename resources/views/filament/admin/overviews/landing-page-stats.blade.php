{{-- §3.10 F-3.10-001 "stats" (WP-40). Rendered from LandingPageStats, the
     §2.6 owner — identical numbers to the customer's own analytics page. --}}
<div class="space-y-4">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            'visits' => number_format($summary['visits']),
            'unique_visitors' => number_format($summary['unique_visitors']),
            'conversions' => number_format($summary['conversions']),
            'conversion_rate' => $summary['conversion_rate'].'%',
        ] as $key => $value)
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('reports.overviews.landing_pages.'.$key) }}
                </div>
                <div class="text-xl font-semibold" data-testid="lp-{{ $key }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    @if ($sources !== [])
        <div class="overflow-x-auto">
            <table class="w-full min-w-[420px] text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-3">{{ __('reports.overviews.landing_pages.stats_source') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('reports.overviews.landing_pages.visits') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('reports.overviews.landing_pages.conversions') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('reports.overviews.landing_pages.conversion_rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sources as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3">{{ $row['source'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['visits']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['conversions']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['conversion_rate'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
