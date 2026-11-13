<x-filament-panels::page>
    @php($info = $this->info())
    @php($performance = $this->performance())
    @php($cpu = $this->cpu())
    @php($cache = $this->cacheLayer())
    @php($workers = $this->workers())

    <div wire:poll.30s class="space-y-6">
        <x-filament::section :heading="__('ops.dashboard.overview')">
            <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                @foreach ($info->versions() as $key => $value)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $key }}</dt>
                        <dd class="font-mono">{{ $value }}</dd>
                    </div>
                @endforeach
                @foreach ($info->drivers() as $key => $value)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.driver', ['name' => $key]) }}</dt>
                        <dd class="font-mono">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('ops.dashboard.queues')">
            @if (! $performance['queues']['supported'])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('reports.admin.queue_monitor.unsupported', ['driver' => $performance['queues']['driver']]) }}
                </p>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.pending') }}</div>
                        <div class="text-2xl font-semibold">{{ number_format((int) $performance['queues']['total_pending']) }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.failed') }}</div>
                        <div class="text-2xl font-semibold">{{ number_format($performance['queues']['failed']) }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.alive_workers') }}</div>
                        <div class="text-2xl font-semibold">{{ number_format($workers['alive']) }}</div>
                        @if ($workers['draining'])
                            <div class="text-xs text-warning-600">{{ __('ops.workers.drain_active') }}</div>
                        @endif
                    </div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('ops.dashboard.capacity')">
            <p class="text-sm">
                {{ __('ops.dashboard.cores', ['cores' => $cpu['cores'], 'source' => $cpu['source']]) }}
            </p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('ops.dashboard.suggested_workers', [
                    'sending' => $cpu['suggested']['sending'],
                    'general' => $cpu['suggested']['general'],
                    'total' => $cpu['suggested']['total'],
                ]) }}
            </p>
            <p class="mt-3 text-sm">
                {{ __('ops.dashboard.cache_layer', [
                    'state' => $cache['enabled'] ? __('ops.dashboard.enabled') : __('ops.dashboard.disabled'),
                    'driver' => $cache['driver'],
                ]) }}
                @unless ($cache['taggable'])
                    <span class="text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.cache_not_taggable') }}</span>
                @endunless
            </p>
        </x-filament::section>

        <x-filament::section :heading="__('ops.dashboard.cron')">
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('ops.dashboard.cron_note') }}</p>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">{{ __('ops.dashboard.job') }}</th>
                            <th class="py-2 pr-4">{{ __('ops.dashboard.status') }}</th>
                            <th class="py-2 pr-4">{{ __('ops.dashboard.processed') }}</th>
                            <th class="py-2">{{ __('ops.dashboard.started_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->recentCronRuns() as $run)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-mono">{{ $run->job }}</td>
                                <td class="py-2 pr-4">{{ $run->status }}</td>
                                <td class="py-2 pr-4">{{ $run->processed }}</td>
                                <td class="py-2">{{ $run->started_at }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
