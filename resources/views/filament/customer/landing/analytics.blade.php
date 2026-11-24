<x-filament-panels::page>
    {{-- Landing tracking report (spec §2.6 F-2.6-008). --}}

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            'visits' => __('landing.analytics.visits'),
            'unique_visitors' => __('landing.analytics.unique_visitors'),
            'conversions' => __('landing.analytics.conversions'),
        ] as $key => $label)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($summary[$key]) }}</div>
            </div>
        @endforeach

        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('landing.analytics.conversion_rate') }}
            </div>
            <div class="mt-1 text-2xl font-semibold">{{ number_format($summary['conversion_rate'], 2) }}%</div>
        </div>
    </div>

    <h2 class="mt-8 text-base font-semibold">{{ __('landing.analytics.by_source') }}</h2>

    @if (count($bySource) === 0)
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('landing.analytics.empty') }}</p>
    @else
        <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left dark:bg-gray-800">
                    <tr>
                        <th class="p-3">{{ __('landing.analytics.source') }}</th>
                        <th class="p-3">{{ __('landing.analytics.visits') }}</th>
                        <th class="p-3">{{ __('landing.analytics.unique_visitors') }}</th>
                        <th class="p-3">{{ __('landing.analytics.conversions') }}</th>
                        <th class="p-3">{{ __('landing.analytics.conversion_rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bySource as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="p-3 font-medium">{{ $row['source'] }}</td>
                            <td class="p-3">{{ number_format($row['visits']) }}</td>
                            <td class="p-3">{{ number_format($row['unique_visitors']) }}</td>
                            <td class="p-3">{{ number_format($row['conversions']) }}</td>
                            <td class="p-3">{{ number_format($row['conversion_rate'], 2) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('landing.analytics.privacy_note') }}</p>
</x-filament-panels::page>
