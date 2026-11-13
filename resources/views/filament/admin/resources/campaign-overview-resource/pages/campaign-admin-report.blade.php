<x-filament-panels::page>
    @php($record = $this->getRecord())
    @php($stats = $this->stats())
    @php($links = $this->topLinks())

    <div class="space-y-6">
        <x-filament::section :heading="__('dashboards.campaigns_overview.report.meta_heading')">
            <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('dashboards.campaigns_overview.report.customer') }}
                    </dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                        {{ $record->customer()->withoutGlobalScopes()->first()?->email ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('dashboards.campaigns_overview.report.subject') }}
                    </dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $record->subject ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('dashboards.campaigns_overview.report.list') }}
                    </dt>
                    <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $record->list?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('dashboards.campaigns_overview.report.status') }}
                    </dt>
                    <dd class="mt-1">
                        <x-filament::badge :color="$record->status->color()">
                            {{ $record->status->label() }}
                        </x-filament::badge>
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('dashboards.campaigns_overview.report.stats_heading')">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ([
                    'recipients' => number_format($stats['recipients']),
                    'delivered' => number_format($stats['delivered']),
                    'failed' => number_format($stats['failed']),
                    'opens' => number_format($stats['opens']),
                    'unique_opens' => number_format($stats['unique_opens']),
                    'open_rate' => $stats['open_rate'].'%',
                    'clicks' => number_format($stats['clicks']),
                    'unique_clicks' => number_format($stats['unique_clicks']),
                    'click_rate' => $stats['click_rate'].'%',
                    'bounced' => number_format($stats['bounced']),
                    'unsubscribes' => number_format($stats['unsubscribes']),
                    'complaints' => number_format($stats['complaints']),
                ] as $key => $value)
                    <div class="rounded-xl bg-gray-50 p-4 text-center dark:bg-white/5">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('dashboards.campaigns_overview.report.'.$key) }}
                        </div>
                        <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('dashboards.campaigns_overview.report.top_links')">
            @if ($links === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.campaigns_overview.report.no_links') }}
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('dashboards.campaigns_overview.report.url') }}</th>
                                <th class="py-2 pr-4">{{ __('dashboards.campaigns_overview.report.link_clicks') }}</th>
                                <th class="py-2 pr-4">{{ __('dashboards.campaigns_overview.report.unique_clickers') }}</th>
                                <th class="py-2">{{ __('dashboards.campaigns_overview.report.percent') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($links as $link)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="max-w-md truncate py-1.5 pr-4">{{ $link['url'] }}</td>
                                    <td class="py-1.5 pr-4">{{ number_format($link['clicks']) }}</td>
                                    <td class="py-1.5 pr-4">{{ number_format($link['unique_clickers']) }}</td>
                                    <td class="py-1.5">{{ $link['percent'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
