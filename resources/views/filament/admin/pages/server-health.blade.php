{{-- Server health / error-rate dashboard (spec §3.13.1 F-3.13.1-007, WP-33). --}}
<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-5">
        @foreach ([
            'servers' => $totals['servers'],
            'sent' => number_format($totals['sent']),
            'failed' => number_format($totals['failed']),
            'error_rate' => number_format($totals['error_rate'] * 100, 2) . '%',
            'unhealthy' => $totals['unhealthy'],
        ] as $key => $value)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('deliverability.health.totals.' . $key) }}
                </div>
                <div class="text-2xl font-bold">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('deliverability.health.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            @foreach (['server', 'type', 'status', 'sent', 'failed', 'error_rate', 'health', 'last_used', 'last_error'] as $column)
                                <th class="px-2 py-2 font-medium">{{ __('deliverability.health.columns.' . $column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="px-2 py-2">{{ $row['name'] }}</td>
                                <td class="px-2 py-2">{{ $row['type'] }}</td>
                                <td class="px-2 py-2">{{ $row['status'] }}</td>
                                <td class="px-2 py-2">{{ number_format($row['sent']) }}</td>
                                <td class="px-2 py-2">{{ number_format($row['failed']) }}</td>
                                <td class="px-2 py-2">{{ number_format($row['error_rate'] * 100, 2) }}%</td>
                                <td class="px-2 py-2">
                                    <x-filament::badge :color="match ($row['health']) {
                                        'critical' => 'danger',
                                        'warning' => 'warning',
                                        default => 'success',
                                    }">
                                        {{ __('deliverability.status.' . $row['health']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-2 py-2">
                                    {{ $row['last_used_at']?->diffForHumans() ?? __('warmup.labels.never') }}
                                </td>
                                <td class="px-2 py-2 max-w-xs truncate">{{ $row['last_error'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
