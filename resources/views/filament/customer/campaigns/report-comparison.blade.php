{{-- §2.3.8 F-2.3.8-003 — this campaign vs account average and the previous one. --}}
<x-filament::section :heading="__('reports.comparison.title')">
    @if ($benchmarks['average'] === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.comparison.none') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-3">{{ __('reports.comparison.metric') }}</th>
                        <th class="py-1 pr-3">{{ __('reports.comparison.this_campaign') }}</th>
                        <th class="py-1 pr-3">{{ __('reports.comparison.account_average') }}</th>
                        <th class="py-1 pr-3">{{ __('reports.comparison.delta') }}</th>
                        @if ($benchmarks['previous'] !== null)
                            <th class="py-1">{{ __('reports.comparison.previous_campaign') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach (\App\Services\Reports\CampaignComparisonService::METRICS as $metric)
                        @php($value = (float) ($benchmarks['stats'][$metric] ?? 0))
                        @php($avg = (float) ($benchmarks['average'][$metric] ?? 0))
                        @php($delta = round($value - $avg, 1))
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1 pr-3">{{ __('reports.stats.'.$metric) }}</td>
                            <td class="py-1 pr-3 tabular-nums">{{ $value }}</td>
                            <td class="py-1 pr-3 tabular-nums text-gray-500 dark:text-gray-400">{{ $avg }}</td>
                            <td @class([
                                'py-1 pr-3 tabular-nums',
                                'text-success-600' => $delta > 0,
                                'text-danger-600' => $delta < 0,
                                'text-gray-400' => $delta == 0,
                            ])>{{ $delta > 0 ? '+' : '' }}{{ $delta }}</td>
                            @if ($benchmarks['previous'] !== null)
                                <td class="py-1 tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $benchmarks['previous']['stats'][$metric] ?? 0 }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('reports.comparison.basis', ['count' => $benchmarks['average_basis']]) }}
            @if ($benchmarks['previous'] !== null)
                · {{ $benchmarks['previous']['name'] }}
            @endif
        </p>
    @endif
</x-filament::section>
