<x-filament-widgets::widget>
    <x-filament::section :heading="__('reports.admin.lockouts.heading')">
        <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
            {{ __('reports.admin.lockouts.hint') }}
        </p>

        @php($lockouts = $this->lockouts())

        @if ($lockouts === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.admin.lockouts.none') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">{{ __('reports.columns.ip') }}</th>
                            <th class="py-2 pr-4">{{ __('reports.admin.lockouts.attempts') }}</th>
                            <th class="py-2 pr-4">{{ __('reports.admin.lockouts.lifts_in') }}</th>
                            <th class="py-2">{{ __('reports.admin.lockouts.last_attempt') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lockouts as $lockout)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-mono">{{ $lockout['ip'] }}</td>
                                <td class="py-2 pr-4">{{ number_format($lockout['attempts']) }}</td>
                                <td class="py-2 pr-4">{{ __('reports.admin.lockouts.seconds', ['seconds' => $lockout['available_in']]) }}</td>
                                <td class="py-2">{{ $lockout['last_attempt']?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
