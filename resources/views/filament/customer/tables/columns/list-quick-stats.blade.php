{{-- Per-list quick stats popover (spec F-2.2.1-001/F-2.2.1-005). Counts come
     from the withCount aggregates added in EmailListResource::table(). --}}
@php
    $record = $getRecord();

    $rows = [
        ['label' => __('subscribers.status.confirmed'), 'count' => (int) $record->confirmed_count, 'color' => '#1baf7a'],
        ['label' => __('subscribers.status.unconfirmed'), 'count' => (int) $record->unconfirmed_count, 'color' => '#eda100'],
        ['label' => __('subscribers.status.unsubscribed'), 'count' => (int) $record->unsubscribed_count, 'color' => '#9ca3af'],
        ['label' => __('subscribers.status.bounced'), 'count' => (int) $record->bounced_count, 'color' => '#e34948'],
        ['label' => __('subscribers.status.blacklisted'), 'count' => (int) $record->blacklisted_count, 'color' => '#4a3aa7'],
    ];
@endphp

<div
    x-data="{ open: false }"
    x-on:mouseenter="open = true"
    x-on:mouseleave="open = false"
    class="fi-list-quick-stats"
    style="position: relative; display: inline-block; padding: 0.25rem 0;"
>
    <span style="font-weight: 600;">{{ number_format((int) $record->subscribers_count) }}</span>
    <span style="display: inline-flex; gap: 0.25rem; margin-inline-start: 0.375rem; vertical-align: middle;">
        @foreach ($rows as $row)
            @if ($row['count'] > 0)
                <span style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; display: inline-block; background: {{ $row['color'] }};" title="{{ $row['label'] }}: {{ number_format($row['count']) }}"></span>
            @endif
        @endforeach
    </span>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity
        style="position: absolute; z-index: 30; top: 100%; inset-inline-start: 0; min-width: 14rem; padding: 0.75rem; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.2); background: rgb(var(--gray-50, 250 250 249)); border: 1px solid rgb(var(--gray-200, 229 229 228));"
        class="dark:!bg-gray-800"
    >
        <p style="font-weight: 600; margin-bottom: 0.5rem;">{{ __('lists.index.quick_stats') }}</p>
        <dl style="display: grid; gap: 0.25rem;">
            @foreach ($rows as $row)
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt style="display: flex; align-items: center; gap: 0.375rem;">
                        <span style="width: 0.5rem; height: 0.5rem; border-radius: 9999px; display: inline-block; background: {{ $row['color'] }};"></span>
                        {{ $row['label'] }}
                    </dt>
                    <dd style="font-variant-numeric: tabular-nums;">{{ number_format($row['count']) }}</dd>
                </div>
            @endforeach
            <div style="display: flex; justify-content: space-between; gap: 1rem; border-top: 1px solid rgb(var(--gray-200, 229 229 228)); margin-top: 0.25rem; padding-top: 0.375rem;">
                <dt style="font-weight: 600;">{{ __('lists.index.total') }}</dt>
                <dd style="font-weight: 600; font-variant-numeric: tabular-nums;">{{ number_format((int) $record->subscribers_count) }}</dd>
            </div>
        </dl>
    </div>
</div>
