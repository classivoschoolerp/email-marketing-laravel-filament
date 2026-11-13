<x-filament-panels::page>
    <div wire:poll.15s class="space-y-6">
        @if ($this->draining())
            <x-filament::section>
                <p class="text-sm text-warning-600">
                    {{ __('ops.workers.drain_active') }}
                    @php($state = $this->drainState())
                    @if ($state !== null)
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('ops.workers.drain_meta', ['at' => $state['at'] ?? '—', 'by' => $state['by'] ?? '—']) }}
                        </span>
                    @endif
                </p>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('ops.workers.heading')">
            <x-slot name="description">{{ __('ops.workers.description') }}</x-slot>

            @php($nodes = $this->nodes())

            @if ($nodes->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('ops.workers.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('ops.workers.node') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.workers.pid') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.workers.queues') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.workers.current_job') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.workers.processed') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.workers.last_beat') }}</th>
                                <th class="py-2">{{ __('ops.workers.state') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($nodes as $node)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 font-mono">{{ $node->node }}</td>
                                    <td class="py-2 pr-4 font-mono">{{ $node->pid }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $node->queues ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $node->current_job ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        {{ $node->jobs_processed }}
                                        @if ($node->jobs_failed > 0)
                                            <span class="text-danger-600">/ {{ $node->jobs_failed }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">{{ $node->last_heartbeat_at?->diffForHumans() ?? '—' }}</td>
                                    <td class="py-2">
                                        @if ($node->alive())
                                            <span class="text-success-600">{{ __('ops.workers.alive') }}</span>
                                        @else
                                            <span class="text-gray-500 dark:text-gray-400">{{ __('ops.workers.stale') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('ops.workers.setup_note') }}</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
