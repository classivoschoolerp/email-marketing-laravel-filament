<x-filament-panels::page>
    <div wire:poll.10s class="space-y-6">
        @php($monitor = $this->monitor())
        @php($queues = $monitor->queues())

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-4 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('reports.admin.queue_monitor.total_pending') }}
                </div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ number_format(array_sum(array_column($queues, 'pending'))) }}
                </div>
            </div>
            <div class="rounded-xl bg-white p-4 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('reports.admin.queue_monitor.total_reserved') }}
                </div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ number_format(array_sum(array_column($queues, 'reserved'))) }}
                </div>
            </div>
            <div class="rounded-xl bg-white p-4 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('reports.admin.queue_monitor.failed') }}
                </div>
                <div class="mt-1 text-2xl font-semibold {{ $monitor->failedCount() > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">
                    {{ number_format($monitor->failedCount()) }}
                </div>
                <a href="{{ \App\Filament\Admin\Resources\FailedJobResource::getUrl('index', panel: 'admin') }}"
                   class="mt-1 inline-block text-xs font-medium text-primary-600 hover:underline">
                    {{ __('reports.admin.queue_monitor.view_failed') }}
                </a>
            </div>
        </div>

        @if (! $monitor->supported())
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('reports.admin.queue_monitor.unsupported', ['driver' => config('queue.default')]) }}
                </p>
            </x-filament::section>
        @else
            <x-filament::section :heading="__('reports.admin.queue_monitor.per_queue')">
                <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('reports.admin.queue_monitor.horizon_note') }}
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('reports.admin.queue_monitor.queue') }}</th>
                                <th class="py-2 pr-4">{{ __('reports.admin.queue_monitor.pending') }}</th>
                                <th class="py-2 pr-4">{{ __('reports.admin.queue_monitor.reserved') }}</th>
                                <th class="py-2">{{ __('reports.admin.queue_monitor.oldest_pending') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($queues as $queue)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 font-mono">{{ $queue['queue'] }}</td>
                                    <td class="py-2 pr-4">{{ number_format($queue['pending']) }}</td>
                                    <td class="py-2 pr-4">{{ number_format($queue['reserved']) }}</td>
                                    <td class="py-2">
                                        @if ($queue['oldest_pending_seconds'] !== null)
                                            {{ __('reports.admin.queue_monitor.age_seconds', ['seconds' => $queue['oldest_pending_seconds']]) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
