<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.admin.queue_health.heading')">
        @if (! $supported)
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                {{ __('dashboards.admin.queue_health.unsupported', ['driver' => config('queue.default')]) }}
            </p>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-gray-50 p-4 text-center dark:bg-white/5">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.admin.queue_health.pending') }}
                </div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ number_format($pending) }}
                </div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 text-center dark:bg-white/5">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.admin.queue_health.in_flight') }}
                </div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ number_format($reserved) }}
                </div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 text-center dark:bg-white/5">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.admin.queue_health.failed') }}
                </div>
                <div class="mt-1 text-2xl font-semibold {{ $failed > 0 ? 'text-danger-600' : 'text-gray-950 dark:text-white' }}">
                    {{ number_format($failed) }}
                </div>
            </div>
        </div>

        <div class="mt-3 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span>
                @if ($oldestSeconds !== null)
                    {{ __('dashboards.admin.queue_health.oldest', ['age' => \Carbon\CarbonInterval::seconds($oldestSeconds)->cascade()->forHumans(short: true)]) }}
                @endif
                {{ __('dashboards.admin.queue_health.workers_note') }}
            </span>
            <a href="{{ $monitorUrl }}" class="whitespace-nowrap font-medium text-primary-600 hover:underline">
                {{ __('dashboards.admin.queue_health.open_monitor') }}
            </a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
