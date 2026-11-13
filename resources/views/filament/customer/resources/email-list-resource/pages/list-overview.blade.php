<x-filament-panels::page>
    {{-- Header widgets (status cards, growth chart, source chart) render automatically. --}}

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));">
        {{-- Subscribers by country (F-2.2.3-004): tile cartogram + table + share
             bars, rendered by the same GeoMapRenderer the campaign geo report
             uses so the two screens can never disagree (D-91). --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('lists.overview.country_heading') }}</x-slot>

            @php $countries = $this->countryBreakdown(); @endphp

            @if ($countries === [])
                <p class="fi-color-gray" style="font-size: 0.875rem;">{{ __('lists.overview.country_empty') }}</p>
            @else
                @php $map = $this->countryMap(); @endphp

                <div style="overflow-x: auto; margin-bottom: 0.75rem;">
                    {!! $map['svg'] !!}
                </div>
                <p class="fi-color-gray" style="font-size: 0.75rem; margin-bottom: 0.75rem;">
                    {{ __('reports.geo.map_hint') }}
                </p>

                <table style="width: 100%; font-size: 0.875rem;">
                    <thead>
                        <tr style="text-align: start;">
                            <th style="text-align: start; padding: 0.25rem 0;">{{ __('lists.overview.country') }}</th>
                            <th style="text-align: end; padding: 0.25rem 0;">{{ __('lists.overview.subscribers') }}</th>
                            <th style="text-align: end; padding: 0.25rem 0;">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($countries as $row)
                            <tr>
                                <td style="padding: 0.25rem 0;">{{ $row['name'] }}</td>
                                <td style="text-align: end; font-variant-numeric: tabular-nums;">{{ number_format($row['count']) }}</td>
                                <td style="text-align: end; font-variant-numeric: tabular-nums; min-width: 7rem;">
                                    <span style="display: inline-block; vertical-align: middle; height: 4px; border-radius: 4px; background: #2a78d6; width: {{ max(2, (int) $row['percent']) }}%; max-width: 5rem;"></span>
                                    {{ $row['percent'] }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- Activity timeline (F-2.2.3-007). --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('lists.overview.timeline_heading') }}</x-slot>

            @php $events = $this->activityTimeline(); @endphp

            @if ($events === [])
                <p class="fi-color-gray" style="font-size: 0.875rem;">{{ __('lists.overview.timeline_empty') }}</p>
            @else
                <ol style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                    @foreach ($events as $event)
                        <li style="display: flex; justify-content: space-between; gap: 1rem;">
                            <span>
                                <x-filament::badge :color="match ($event['type']) { 'subscribed' => 'info', 'confirmed' => 'success', default => 'gray' }" style="margin-inline-end: 0.375rem;">
                                    {{ __('lists.overview.timeline_' . $event['type']) }}
                                </x-filament::badge>
                                {{ $event['email'] }}
                            </span>
                            <time datetime="{{ $event['at']?->toIso8601String() }}" style="white-space: nowrap; color: rgb(var(--gray-500, 113 113 107));">
                                {{ $event['at']?->diffForHumans() }}
                            </time>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-filament::section>

        {{-- Recent campaigns sent to this list, with real numbers from
             CampaignReportService (F-2.2.3-006). --}}
        <x-filament::section style="grid-column: 1 / -1;">
            <x-slot name="heading">{{ __('lists.overview.campaigns_heading') }}</x-slot>

            @php($campaigns = $this->recentCampaigns())

            @if (empty($campaigns))
                <p class="fi-color-gray" style="font-size: 0.875rem;">{{ __('lists.overview.campaigns_empty') }}</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left;">
                                <th style="padding: 0.375rem 0.5rem;">{{ __('lists.overview.campaign') }}</th>
                                <th style="padding: 0.375rem 0.5rem;">{{ __('lists.overview.campaign_sent_at') }}</th>
                                <th style="padding: 0.375rem 0.5rem; text-align: right;">{{ __('lists.overview.campaign_delivered') }}</th>
                                <th style="padding: 0.375rem 0.5rem; text-align: right;">{{ __('lists.overview.campaign_opens') }}</th>
                                <th style="padding: 0.375rem 0.5rem; text-align: right;">{{ __('lists.overview.campaign_clicks') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($campaigns as $row)
                                <tr>
                                    <td style="padding: 0.375rem 0.5rem;">{{ $row['campaign']->name }}</td>
                                    <td style="padding: 0.375rem 0.5rem;">{{ $row['sent_at']?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td style="padding: 0.375rem 0.5rem; text-align: right;">{{ number_format($row['delivered']) }}</td>
                                    <td style="padding: 0.375rem 0.5rem; text-align: right;">
                                        {{ number_format($row['unique_opens']) }} ({{ $row['open_rate'] }}%)
                                    </td>
                                    <td style="padding: 0.375rem 0.5rem; text-align: right;">
                                        {{ number_format($row['unique_clicks']) }} ({{ $row['click_rate'] }}%)
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
