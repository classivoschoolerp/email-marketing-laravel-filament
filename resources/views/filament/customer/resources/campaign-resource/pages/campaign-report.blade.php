<x-filament-panels::page>
    @php($stats = $this->stats())

    {{-- Header stats (F-2.3.8-001); wire:poll refreshes them live while the
         campaign is sending (F-2.3.8-021). --}}
    <div @if ($this->isSending()) wire:poll.10s @endif class="space-y-6">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                'recipients' => number_format($stats['recipients']),
                'delivered' => number_format($stats['delivered']),
                'bounced' => number_format($stats['bounced']),
                'opens' => number_format($stats['unique_opens']).' / '.number_format($stats['opens']),
                'open_rate' => $stats['open_rate'].'%',
                'clicks' => number_format($stats['unique_clicks']).' / '.number_format($stats['clicks']),
                'click_rate' => $stats['click_rate'].'%',
                'ctor' => $stats['ctor'].'%',
                'unsubscribes' => number_format($stats['unsubscribes']),
                'complaints' => number_format($stats['complaints']),
                'forwards' => number_format($stats['forwards']),
                'failed' => number_format($stats['failed']),
            ] as $key => $value)
                <div class="rounded-xl bg-white p-4 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('reports.stats.'.$key) }}
                    </div>
                    <div class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>

                    @if ($key === 'bounced')
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('reports.stats.bounce_split', [
                                'hard' => number_format($stats['bounced_hard']),
                                'soft' => number_format($stats['bounced_soft']),
                                'internal' => number_format($stats['bounced_internal']),
                            ]) }}
                        </div>
                    @elseif ($key === 'opens' || $key === 'clicks')
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('reports.stats.unique_total_hint') }}
                        </div>
                    @elseif ($key === 'forwards')
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('reports.stats.forwarders_hint', ['count' => number_format($stats['forwarders'])]) }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Detail tabs (F-2.3.8-004..016). --}}
    <x-filament::tabs class="mt-6">
        @foreach ($this->tabs() as $tab)
            <x-filament::tabs.item
                :active="$activeTab === $tab"
                wire:click="$set('activeTab', '{{ $tab }}')"
            >
                {{ __('reports.tabs.'.$tab) }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="mt-4">
        @if ($activeTab === 'opens')
            @livewire(\App\Filament\Customer\Resources\CampaignResource\Widgets\OpensTable::class, ['record' => $this->getRecord()], key('report-opens'))
        @elseif ($activeTab === 'clicks')
            {{-- Click map (F-2.3.8-005): the campaign's own HTML with every
                 anchor badged with its exact click count / rank / share and
                 shaded by that share. Rendered in a fully sandboxed iframe —
                 no scripts, no navigation, no same-origin access — because
                 the document is customer-authored HTML. --}}
            @php($map = $this->clickMap())

            <x-filament::section :heading="__('reports.click_map.heading')" collapsible class="mb-6">
                <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">{{ __('reports.click_map.hint') }}</p>

                @if ($map['html'] === '')
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.click_map.no_content') }}</p>
                @else
                    <div class="mb-3 flex flex-wrap items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ __('reports.click_map.summary', [
                            'anchors' => number_format($map['anchors']),
                            'clicks' => number_format($map['total_clicks']),
                        ]) }}</span>

                        <span class="flex items-center gap-1">
                            <span class="inline-block h-3 w-3 rounded-sm" style="background-color:#f3f4f6;outline:2px solid #9ca3af;"></span>
                            {{ __('reports.click_map.legend_none') }}
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="inline-block h-3 w-3 rounded-sm" style="background-color:#f87171;outline:2px solid #991b1b;"></span>
                            {{ __('reports.click_map.legend_most') }}
                        </span>
                    </div>

                    <iframe
                        title="{{ __('reports.click_map.heading') }}"
                        sandbox=""
                        class="h-[60vh] w-full rounded-lg border border-gray-200 bg-white dark:border-white/10"
                        srcdoc="{{ $map['html'] }}"
                    ></iframe>
                @endif
            </x-filament::section>

            @livewire(\App\Filament\Customer\Resources\CampaignResource\Widgets\ClicksTable::class, ['record' => $this->getRecord()], key('report-clicks'))
        @elseif ($activeTab === 'bounces')
            @livewire(\App\Filament\Customer\Resources\CampaignResource\Widgets\BouncesTable::class, ['record' => $this->getRecord()], key('report-bounces'))
        @elseif ($activeTab === 'unsubscribes')
            @livewire(\App\Filament\Customer\Resources\CampaignResource\Widgets\UnsubscribesTable::class, ['record' => $this->getRecord()], key('report-unsubscribes'))
        @elseif ($activeTab === 'complaints')
            @livewire(\App\Filament\Customer\Resources\CampaignResource\Widgets\ComplaintsTable::class, ['record' => $this->getRecord()], key('report-complaints'))
        @elseif ($activeTab === 'delivery')
            @livewire(\App\Filament\Customer\Resources\CampaignResource\Widgets\DeliveryLogsTable::class, ['record' => $this->getRecord()], key('report-delivery'))
        @elseif ($activeTab === 'top_links')
            {{-- Top clicked links with % share (F-2.3.8-006). --}}
            <x-filament::section :heading="__('reports.tabs.top_links')">
                {{-- F-2.3.8-018 (WP-26): CSV default, XLSX alongside it. --}}
                <div class="mb-3 flex flex-wrap gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-down-tray" wire:click="exportTab('top_links', 'csv')">
                        {{ __('reports.actions.export_csv') }}
                    </x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-table-cells" wire:click="exportTab('top_links', 'xlsx')">
                        {{ __('reports.actions.export_xlsx') }}
                    </x-filament::button>
                </div>

                @forelse ($this->topLinks() as $link)
                    <div class="mb-3">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <span class="truncate font-medium text-gray-950 dark:text-white" title="{{ $link['url'] }}">{{ $link['url'] }}</span>
                            <span class="shrink-0 text-gray-500 dark:text-gray-400">
                                {{ __('reports.top_links.line', [
                                    'clicks' => number_format($link['clicks']),
                                    'unique' => number_format($link['unique_clickers']),
                                    'percent' => $link['percent'],
                                ]) }}
                            </span>
                        </div>
                        <div class="mt-1 h-2 w-full overflow-hidden rounded bg-gray-100 dark:bg-gray-800">
                            <div class="h-2 rounded bg-primary-600" style="width: {{ min(100, $link['percent']) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.empty.top_links') }}</p>
                @endforelse
            </x-filament::section>
        @elseif ($activeTab === 'geo')
            {{-- Geo report (F-2.3.8-013): world map + country/region/city
                 table. The map is an equal-area TILE CARTOGRAM built inline
                 from App\Support\Geo\WorldTileGrid — one square per ISO-3166
                 country, shaded by share of opens. It is deliberately not a
                 boundary choropleth (that needs a vector dataset this build
                 cannot vendor — see docs/e2e-checklist.md), and the caption
                 below says so rather than implying otherwise.
                 Hits without GeoIP data stay in an honest "unknown" figure
                 (D-10). --}}
            @php($map = $this->geoMap())

            <x-filament::section :heading="__('reports.geo.map_heading')" class="mb-6">
                <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">{{ __('reports.geo.map_hint') }}</p>

                @if ($map['total'] === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.empty.geo') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <div class="min-w-[640px]">{!! $map['svg'] !!}</div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ __('reports.geo.map_summary', [
                            'mapped' => number_format($map['mapped']),
                            'total' => number_format($map['total']),
                            'unknown' => number_format($map['unknown']),
                        ]) }}</span>

                        @foreach (app(\App\Services\Reports\GeoMapRenderer::class)->legend($map['max']) as $step)
                            <span class="flex items-center gap-1">
                                <span class="inline-block h-3 w-3 rounded-sm" style="background-color: {{ $step['color'] }}"></span>
                                {{ $step['label'] }}
                            </span>
                        @endforeach
                    </div>

                    @if ($map['rows'] !== [])
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach (array_slice($map['rows'], 0, 10) as $row)
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                    {{ $row['name'] }} · {{ number_format($row['count']) }} · {{ $row['percent'] }}%
                                </span>
                            @endforeach
                        </div>
                    @endif
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('reports.tabs.geo')">
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('reports.geo.hint') }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('reports.geo.country') }}</th>
                                <th class="py-2 pr-4">{{ __('reports.geo.region') }}</th>
                                <th class="py-2 pr-4">{{ __('reports.geo.city') }}</th>
                                <th class="py-2 pr-4">{{ __('reports.columns.opens') }}</th>
                                <th class="py-2">{{ __('reports.columns.share') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->geoRows() as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4">{{ $row['country'] ?? __('reports.breakdowns.unknown') }}</td>
                                    <td class="py-2 pr-4">{{ $row['region'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $row['city'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ number_format($row['count']) }}</td>
                                    <td class="py-2">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-32 overflow-hidden rounded bg-gray-100 dark:bg-gray-800">
                                                <div class="h-2 rounded bg-primary-600" style="width: {{ min(100, $row['percent']) }}%"></div>
                                            </div>
                                            <span class="text-gray-500 dark:text-gray-400">{{ $row['percent'] }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-3 text-gray-500 dark:text-gray-400">{{ __('reports.empty.geo') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @elseif ($activeTab === 'devices')
            {{-- Device / OS / browser / email-client reports (F-2.3.8-014/015/016). --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                @foreach (['device', 'os', 'browser', 'client'] as $dimension)
                    <x-filament::section :heading="__('reports.breakdowns.'.$dimension)">
                        @php($rows = $this->breakdown($dimension))

                        @forelse ($rows as $row)
                            <div class="mb-2">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ $row['label'] === 'unknown' ? __('reports.breakdowns.unknown') : $row['label'] }}
                                    </span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ number_format($row['count']) }} · {{ $row['percent'] }}%</span>
                                </div>
                                <div class="mt-1 h-2 w-full overflow-hidden rounded bg-gray-100 dark:bg-gray-800">
                                    <div class="h-2 rounded bg-primary-600" style="width: {{ min(100, $row['percent']) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.empty.breakdown') }}</p>
                        @endforelse

                        @if ($rows !== [])
                            @livewire(\App\Filament\Customer\Resources\CampaignResource\Widgets\EngagementBreakdownChart::class, ['record' => $this->getRecord(), 'dimension' => $dimension], key('report-breakdown-'.$dimension))
                        @endif
                    </x-filament::section>
                @endforeach
            </div>
        @elseif ($activeTab === 'heat')
            {{-- §2.3.8-017 — subscriber engagement heat table. --}}
            @include('filament.customer.campaigns.report-heat', [
                'heat' => $this->heatRows(),
                'grid' => $this->heatGrid(),
            ])
        @elseif ($activeTab === 'comparison')
            {{-- §2.3.8-003 — vs account average / previous campaign. --}}
            @include('filament.customer.campaigns.report-comparison', [
                'benchmarks' => $this->benchmarks(),
            ])
        @endif
    </div>

    {{-- §2.3.6 F-2.3.6-009/010/011 — per-variant comparison + significance. --}}
    @include('filament.customer.campaigns.report-ab', [
        'variants' => $this->variantStats(),
        'significance' => $this->variantSignificance(),
    ])

    {{-- §2.3.6 F-2.3.6-004 — per-factor effects (multivariate designs only). --}}
    @include('filament.customer.campaigns.report-mvt', [
        'effects' => $this->factorEffects(),
    ])
</x-filament-panels::page>
