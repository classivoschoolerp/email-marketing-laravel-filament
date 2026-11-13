{{-- §3.1 F-3.1-006 server resources (WP-40). Figures from SystemInfoService;
     a metric the host will not report renders as "—", never as 0. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('analytics.resources.heading')">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800" data-testid="resources-disk">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('analytics.resources.disk') }}
                </div>
                <div class="mt-1 text-lg font-semibold">
                    {{ $info->formatBytes($disk['free']) }} / {{ $info->formatBytes($disk['total']) }}
                </div>

                @if ($diskPercent === null)
                    <div class="mt-1 text-xs text-gray-400">{{ __('analytics.resources.unavailable') }}</div>
                @else
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-1.5 rounded-full
                            @if ($diskStatus === 'critical') bg-danger-500
                            @elseif ($diskStatus === 'warning') bg-warning-500
                            @else bg-success-500 @endif"
                            style="width: {{ $diskPercent }}%"></div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('analytics.resources.disk_used', ['percent' => $diskPercent]) }}
                    </div>
                @endif
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800" data-testid="resources-memory">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('analytics.resources.memory') }}
                </div>
                <div class="mt-1 text-lg font-semibold">{{ $info->formatBytes($memoryUsage) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('analytics.resources.memory_detail', [
                        'peak' => $info->formatBytes($memoryPeak),
                        'limit' => $memoryLimit,
                    ]) }}
                </div>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800" data-testid="resources-database">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('analytics.resources.database') }}
                </div>
                <div class="mt-1 text-lg font-semibold">{{ $info->formatBytes($databaseBytes) }}</div>
                @if ($databaseBytes === null)
                    <div class="mt-1 text-xs text-gray-400">{{ __('analytics.resources.unavailable') }}</div>
                @endif
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800" data-testid="resources-storage">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('analytics.resources.storage') }}
                </div>
                <div class="mt-1 text-lg font-semibold">
                    {{ $info->formatBytes((int) array_sum($storage)) }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @foreach ($storage as $path => $bytes)
                        <div>{{ $path }} — {{ $info->formatBytes((int) $bytes) }}</div>
                    @endforeach
                </div>
            </div>
        </div>

        <a href="{{ $systemInfoUrl }}"
           class="mt-3 inline-block text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
            {{ __('analytics.resources.open_system_info') }}
        </a>
    </x-filament::section>
</x-filament-widgets::widget>
