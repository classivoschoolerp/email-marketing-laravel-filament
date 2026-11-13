<x-filament-panels::page>
    <div class="space-y-6">
        {{-- F-2.15.5-001 current plan/group summary --}}
        <x-filament::section :heading="__('dashboards.usage.plan_heading')">
            @if ($groupName !== null)
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-medium">{{ __('dashboards.usage.group') }}:</span>
                    {{ $groupName }}
                </p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.usage.no_group') }}
                </p>
            @endif
        </x-filament::section>

        {{-- F-2.15.5-002 sending quota bars + reset times --}}
        <x-filament::section :heading="__('dashboards.usage.sending_heading')">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach (['hourly', 'daily', 'monthly'] as $window)
                    @php($usage = $sending[$window])
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('dashboards.usage.windows.'.$window) }}
                        </div>
                        <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                            @if ($usage['limit'] === null)
                                {{ __('dashboards.usage.used_unlimited', ['used' => number_format($usage['used'])]) }}
                            @else
                                {{ __('dashboards.usage.used', [
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
                            {{ __('dashboards.usage.resets_at', ['at' => $usage['resets_at']->diffForHumans()]) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- F-2.15.5-003/004 counts vs limits --}}
        <x-filament::section :heading="__('dashboards.usage.objects_heading')">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-5">
                @foreach ($objects as $name => $object)
                    <div class="rounded-xl bg-gray-50 p-4 text-center dark:bg-white/5">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('dashboards.usage.objects.'.$name) }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                            {{ number_format($object['used']) }}
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                / {{ $object['limit'] === null ? __('dashboards.usage.unlimited') : number_format($object['limit']) }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- F-2.15.5-005 storage used --}}
        <x-filament::section :heading="__('dashboards.usage.storage_heading')">
            <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $storage }}</p>
        </x-filament::section>

        {{-- F-3.26.1-009 projected email overage for the open billing window
             (WP-46). Only rendered for plans that actually bill overage. --}}
        @if ($overage !== null)
            <x-filament::section :heading="__('billing.plans.overage.heading')">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('billing.plans.overage.window', ['from' => $overage['from']->toDayDateTimeString()]) }}
                </p>
                <dl class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('billing.plans.overage.included') }}</dt>
                        <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($overage['included']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('billing.plans.overage.used') }}</dt>
                        <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($overage['used']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('billing.plans.overage.billable') }}</dt>
                        <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ number_format($overage['billable']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('billing.plans.overage.projected') }}</dt>
                        <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $overage['amount_formatted'] }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                    @if ($overage['billable'] > 0)
                        {{ __('billing.plans.overage.blocks', ['size' => number_format($overage['unit_size'])]) }}:
                        {{ number_format($overage['blocks']) }} &times; {{ $overage['unit_price_formatted'] }}
                    @else
                        {{ __('billing.plans.overage.none') }}
                    @endif
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('billing.plans.overage.note', ['size' => number_format($overage['unit_size'])]) }}
                </p>
            </x-filament::section>
        @endif

        {{-- F-2.15.5-007 upgrade CTA (billing arrives with a parallel WP) --}}
        @if ($nearLimit)
            <x-filament::section :heading="__('dashboards.usage.upgrade_heading')">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ __('dashboards.usage.upgrade_text') }}
                </p>
                <div class="mt-3">
                    @if ($upgradeUrl !== null)
                        <x-filament::button tag="a" :href="$upgradeUrl" size="sm">
                            {{ __('dashboards.usage.upgrade_link') }}
                        </x-filament::button>
                    @else
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ __('dashboards.usage.upgrade_contact') }}
                        </p>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
