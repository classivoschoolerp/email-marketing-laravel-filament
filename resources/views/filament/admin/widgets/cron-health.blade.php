<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.admin.cron_health.heading')">
        <div @class([
            'mb-3 rounded-lg px-3 py-2 text-xs font-medium',
            'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $stale,
            'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => ! $stale,
        ])>
            {{ __($stale ? 'dashboards.admin.cron_health.heartbeat_stale' : 'dashboards.admin.cron_health.heartbeat_ok', [
                'last' => $lastSeen?->diffForHumans() ?? __('dashboards.admin.cron_health.never'),
            ]) }}
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4">{{ __('dashboards.admin.cron_health.job') }}</th>
                        <th class="py-2 pr-4">{{ __('dashboards.admin.cron_health.last_run') }}</th>
                        <th class="py-2">{{ __('dashboards.admin.cron_health.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jobs as $job => $info)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-1.5 pr-4 font-mono text-xs">{{ $job }}</td>
                            <td class="py-1.5 pr-4 text-xs text-gray-600 dark:text-gray-300">
                                {{ $info['last_run']?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="py-1.5 text-xs">
                                @if ($info['never_ran'])
                                    <x-filament::badge color="gray">
                                        {{ __('dashboards.admin.cron_health.never_ran') }}
                                    </x-filament::badge>
                                @elseif ($stale)
                                    <x-filament::badge color="danger">
                                        {{ __('dashboards.admin.cron_health.overdue') }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge :color="$info['status'] === 'failed' ? 'danger' : ($info['status'] === 'running' ? 'info' : 'success')">
                                        {{ $info['status'] }}
                                    </x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
