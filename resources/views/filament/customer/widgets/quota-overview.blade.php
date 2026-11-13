<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.customer.quota.heading')">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach (['hourly', 'daily', 'monthly'] as $window)
                @php($usage = $sending[$window])
                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('dashboards.customer.quota.'.$window) }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        @if ($usage['limit'] === null)
                            {{ __('dashboards.customer.quota.used_unlimited', ['used' => number_format($usage['used'])]) }}
                        @else
                            {{ __('dashboards.customer.quota.used', [
                                'used' => number_format($usage['used']),
                                'limit' => number_format($usage['limit']),
                            ]) }}
                        @endif
                    </div>
                    @if ($usage['limit'] !== null)
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-2 rounded-full {{ $usage['percent'] >= 90 ? 'bg-danger-500' : ($usage['percent'] >= 75 ? 'bg-warning-500' : 'bg-primary-500') }}"
                                 style="width: {{ $usage['percent'] }}%"></div>
                        </div>
                    @endif
                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('dashboards.customer.quota.resets_in', ['in' => $usage['resets_at']->diffForHumans()]) }}
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.customer.quota.subscriber_quota') }}
                </div>
                <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                    @if ($subscribers['limit'] === null)
                        {{ __('dashboards.customer.quota.used_unlimited', ['used' => number_format($subscribers['used'])]) }}
                    @else
                        {{ __('dashboards.customer.quota.used', [
                            'used' => number_format($subscribers['used']),
                            'limit' => number_format($subscribers['limit']),
                        ]) }}
                    @endif
                </div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.customer.quota.storage') }}
                </div>
                <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $storage }}
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
