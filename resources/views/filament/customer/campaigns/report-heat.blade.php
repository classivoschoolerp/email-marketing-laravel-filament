{{-- §2.3.8 F-2.3.8-017 — subscriber engagement heat table + day/hour grid. --}}
@php($shades = ['bg-gray-100 dark:bg-gray-800', 'bg-primary-100', 'bg-primary-200', 'bg-primary-400', 'bg-primary-600'])
@php($days = [
    __('reports.heat.day').' 0', __('reports.heat.day').' 1', __('reports.heat.day').' 2',
    __('reports.heat.day').' 3', __('reports.heat.day').' 4', __('reports.heat.day').' 5',
    __('reports.heat.day').' 6',
])

<x-filament::section :heading="__('reports.heat.title')">
    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('reports.heat.legend') }}</p>

    @if ($heat['rows'] === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.heat.empty') }}</p>
    @else
        {{-- Day × hour grid of open hits. --}}
        <div class="mb-6 overflow-x-auto">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="pr-2 text-left text-gray-500 dark:text-gray-400">{{ __('reports.heat.hour') }}</th>
                        @for ($hour = 0; $hour < 24; $hour++)
                            <th class="px-0.5 text-center text-[10px] text-gray-400">{{ $hour }}</th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid['matrix'] as $day => $hours)
                        <tr>
                            <td class="pr-2 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::now()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays($day)->isoFormat('ddd') }}
                            </td>
                            @foreach ($hours as $hour => $count)
                                @php($band = app(\App\Services\Reports\EngagementHeatTable::class)->band($count, $grid['max']))
                                <td class="p-0.5">
                                    <div class="h-4 w-4 rounded-sm {{ $shades[$band] }}"
                                         title="{{ $count }}"></div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Per-subscriber heat rows. --}}
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-3">{{ __('reports.heat.subscriber') }}</th>
                        <th class="py-1 pr-3">{{ __('reports.heat.opens') }}</th>
                        <th class="py-1 pr-3">{{ __('reports.heat.clicks') }}</th>
                        <th class="py-1 pr-3">{{ __('reports.columns.last_click') }}</th>
                        <th class="py-1">{{ __('reports.heat.score') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($heat['rows'] as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1 pr-3">{{ $row['email'] ?? $row['subscriber_uid'] }}</td>
                            <td class="py-1 pr-3">{{ number_format($row['opens']) }}</td>
                            <td class="py-1 pr-3">{{ number_format($row['clicks']) }}</td>
                            <td class="py-1 pr-3 text-gray-500 dark:text-gray-400">{{ $row['last_at'] }}</td>
                            <td class="py-1">
                                <div class="h-4 w-16 rounded-sm {{ $shades[$row['band']] }}"></div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
