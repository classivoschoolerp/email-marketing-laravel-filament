<x-filament-panels::page>
    @php($summary = $this->summary())
    @php($index = $this->indexReport())

    <div class="space-y-6">
        {{-- F-6.6-003 slow queries --}}
        <x-filament::section :heading="__('ops.performance.slow_queries')">
            <x-slot name="description">
                @if ($summary['slow_queries']['enabled'])
                    {{ __('ops.performance.slow_queries_on', ['ms' => $summary['slow_queries']['threshold_ms']]) }}
                @else
                    {{ __('ops.performance.slow_queries_off') }}
                @endif
            </x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.performance.recorded_total') }}</div>
                    <div class="text-2xl font-semibold">{{ number_format($summary['slow_queries']['total']) }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.performance.recorded_24h') }}</div>
                    <div class="text-2xl font-semibold">{{ number_format($summary['slow_queries']['last_24h']) }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.performance.slowest') }}</div>
                    <div class="text-2xl font-semibold">
                        {{ $summary['slow_queries']['slowest_ms'] === null ? '—' : $summary['slow_queries']['slowest_ms'].' ms' }}
                    </div>
                </div>
            </div>

            @if (count($this->topSlowQueries()) > 0)
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('ops.performance.sql') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.performance.hits') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.performance.max_ms') }}</th>
                                <th class="py-2">{{ __('ops.performance.avg_ms') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->topSlowQueries() as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 font-mono text-xs break-all">{{ \Illuminate\Support\Str::limit($row['sql'], 180) }}</td>
                                    <td class="py-2 pr-4">{{ $row['hits'] }}</td>
                                    <td class="py-2 pr-4">{{ $row['max_ms'] }}</td>
                                    <td class="py-2">{{ $row['avg_ms'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Queue depth + workers + cache hit rate --}}
        <x-filament::section :heading="__('ops.performance.runtime')">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.pending') }}</div>
                    <div class="text-2xl font-semibold">
                        {{ $summary['queues']['supported'] ? number_format((int) $summary['queues']['total_pending']) : '—' }}
                    </div>
                    @unless ($summary['queues']['supported'])
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('reports.admin.queue_monitor.unsupported', ['driver' => $summary['queues']['driver']]) }}
                        </div>
                    @endunless
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.alive_workers') }}</div>
                    <div class="text-2xl font-semibold">{{ $summary['workers']['alive'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['workers']['note'] }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.performance.cpu_cores') }}</div>
                    <div class="text-2xl font-semibold">{{ $summary['workers']['cores'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('ops.performance.suggested', ['total' => $summary['workers']['suggested']['total']]) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.performance.cache_hit_rate') }}</div>
                    <div class="text-2xl font-semibold">
                        {{ $summary['cache']['supported'] && $summary['cache']['ratio'] !== null ? $summary['cache']['ratio'].'%' : '—' }}
                    </div>
                    @unless ($summary['cache']['supported'])
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['cache']['note'] }}</div>
                    @endunless
                </div>
            </div>
        </x-filament::section>

        {{-- F-6.6-004 index health --}}
        <x-filament::section :heading="__('ops.index_health.heading')">
            <x-slot name="description">
                {{ __('ops.index_health.driver', ['driver' => $index['driver']]) }}
            </x-slot>

            @if ($index['missing'] === [])
                <p class="text-sm text-success-600">{{ __('ops.index_health.no_missing') }}</p>
            @else
                <p class="text-sm text-danger-600">{{ __('ops.index_health.missing_heading') }}</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($index['missing'] as $row)
                        <li class="font-mono text-xs break-all">{{ $row['ddl'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($index['duplicates'] !== [])
                <p class="mt-4 text-sm text-warning-600">{{ __('ops.index_health.duplicates_heading') }}</p>
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($index['duplicates'] as $row)
                        <li>{{ __('ops.index_health.duplicate_line', ['table' => $row['table'], 'index' => $row['index'], 'other' => $row['duplicate_of']]) }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                @if (! $index['unused']['supported'])
                    {{ $index['unused']['note'] }}
                @elseif ($index['unused']['rows'] === [])
                    {{ __('ops.index_health.no_unused') }}
                @else
                    {{ __('ops.index_health.unused_heading') }}
                    <span class="font-mono">
                        @foreach ($index['unused']['rows'] as $row){{ $row['table'] }}.{{ $row['index'] }}@if (! $loop->last), @endif @endforeach
                    </span>
                @endif
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
