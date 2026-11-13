{{-- Live sending progress bar (spec §2.3.1 F-2.3.1-003). Values come from
     the WP-13 delivery counters — the bar shows 0% until the sending engine
     writes them; the table polls so it updates live. --}}
@php
    /** @var \App\Models\Campaign $record */
    $record = $getRecord();
@endphp
@if ($record->status === \App\Enums\CampaignStatus::Sending)
    @php
        $percent = $record->sendProgressPercent();
        $rate = $record->send_rate_per_hour;
    @endphp
    <div class="w-32">
        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
            <div class="h-2 rounded-full bg-primary-600 transition-all" style="width: {{ $percent }}%"></div>
        </div>
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
            {{ $percent }}% · {{ number_format((int) $record->delivery_sent) }}/{{ number_format((int) $record->delivery_total) }}@if ($rate) · {{ number_format($rate) }}/h @endif
        </p>
    </div>
@endif
