<x-public-layout :title="__('campaigns.public_report.title', ['name' => $campaign->name])">
    {{-- Read-only shared campaign report (spec §1.6 F-1.6-004 / §2.3.4
         F-2.3.4-020). Delivery numbers come from the WP-13 counters and the
         WP-09 tracking tables — zeros until the campaign has real sends. --}}
    <h1 class="text-xl font-bold">{{ $campaign->name }}</h1>
    @if ($campaign->subject)
        <p class="mt-1 text-sm text-gray-600">{{ $campaign->subject }}</p>
    @endif

    <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
        @foreach ([
            'recipients' => $stats['recipients'],
            'delivered' => $stats['delivered'],
            'unique_opens' => $stats['unique_opens'],
            'opens' => $stats['opens'],
            'unique_clicks' => $stats['unique_clicks'],
            'clicks' => $stats['clicks'],
            'unsubscribes' => $stats['unsubscribes'],
        ] as $key => $value)
            <div class="rounded-lg bg-gray-50 p-4 text-center">
                <dt class="text-xs uppercase tracking-wide text-gray-500">
                    {{ __('campaigns.public_report.'.$key) }}
                </dt>
                <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    {{-- WP-17: rates + bounce/complaint numbers from CampaignReportService
         (same single source as the customer report, D-64). --}}
    <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
        @foreach ([
            'open_rate' => $header['open_rate'].'%',
            'click_rate' => $header['click_rate'].'%',
            'ctor' => $header['ctor'].'%',
            'bounced' => number_format($header['bounced']),
            'complaints' => number_format($header['complaints']),
        ] as $key => $value)
            <div class="rounded-lg bg-gray-50 p-4 text-center">
                <dt class="text-xs uppercase tracking-wide text-gray-500">
                    {{ __('reports.stats.'.$key) }}
                </dt>
                <dd class="mt-1 text-2xl font-semibold text-gray-900">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($topLinks !== [])
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wide text-gray-500">
            {{ __('reports.tabs.top_links') }}
        </h2>
        <div class="mt-3 space-y-3">
            @foreach ($topLinks as $link)
                <div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <span class="truncate font-medium text-gray-900" title="{{ $link['url'] }}">{{ $link['url'] }}</span>
                        <span class="shrink-0 text-gray-500">
                            {{ __('reports.top_links.line', [
                                'clicks' => number_format($link['clicks']),
                                'unique' => number_format($link['unique_clickers']),
                                'percent' => $link['percent'],
                            ]) }}
                        </span>
                    </div>
                    <div class="mt-1 h-2 w-full overflow-hidden rounded bg-gray-100">
                        <div class="h-2 rounded bg-blue-600" style="width: {{ min(100, $link['percent']) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($campaign->send_finished_at !== null)
        <p class="mt-6 text-xs text-gray-500">
            {{ __('campaigns.public_report.finished_at', ['date' => $campaign->send_finished_at->toDayDateTimeString()]) }}
        </p>
    @endif
</x-public-layout>
