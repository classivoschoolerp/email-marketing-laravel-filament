<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.admin.license.heading')">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.admin.license.status') }}
                </dt>
                <dd class="mt-1">
                    <x-filament::badge :color="$valid ? 'success' : ($status === 'unknown' ? 'gray' : 'danger')">
                        {{ __('dashboards.admin.license.statuses.'.$status) }}
                    </x-filament::badge>
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.admin.license.type') }}
                </dt>
                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $type ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.admin.license.expires_at') }}
                </dt>
                <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                    {{ $expiresAt ?? __('dashboards.admin.license.perpetual') }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.admin.license.last_checked') }}
                </dt>
                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $lastCheckedAt ?? '—' }}</dd>
            </div>
        </dl>

        @if ($renewalUrl !== null)
            <div class="mt-3">
                <a href="{{ $renewalUrl }}" target="_blank" rel="noopener"
                   class="text-sm font-medium text-primary-600 hover:underline">
                    {{ __('dashboards.admin.license.renew') }}
                </a>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
