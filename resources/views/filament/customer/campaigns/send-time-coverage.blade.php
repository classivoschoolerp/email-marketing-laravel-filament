{{-- §2.3.4 F-2.3.4-011 — the Send Time Optimization read-out.

     Deliberately literal: it reports how many recipients have a PERSONAL
     prediction, how many fall back to the account-level hour, and how many
     the optimizer cannot speak for at all. An empty profile set says so
     instead of drawing a chart of nothing. --}}
@php($total = (int) ($coverage['total'] ?? 0))

@if ($total === 0)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('campaigns.options.send_time_no_data') }}</p>
@else
    @php($max = max($coverage['hours'] ?: [1]))

    <div class="space-y-2 text-sm">
        <p>
            {{ __('campaigns.options.send_time_sources', [
                'subscriber' => $coverage['subscriber'] ?? 0,
                'account' => $coverage['account'] ?? 0,
                'none' => $coverage['none'] ?? 0,
                'total' => $total,
            ]) }}
        </p>

        @if ($coverage['computed_at'] !== null)
            <p @class(['text-xs', 'text-warning-600' => $coverage['stale'], 'text-gray-500 dark:text-gray-400' => ! $coverage['stale']])>
                {{ __('campaigns.options.send_time_computed_at', ['at' => $coverage['computed_at']]) }}
                @if ($coverage['stale'])
                    {{ __('campaigns.options.send_time_stale') }}
                @endif
            </p>
        @endif

        @if ($coverage['hours'] !== [])
            <div class="space-y-1">
                @foreach ($coverage['hours'] as $hour => $count)
                    <div class="flex items-center gap-2">
                        <span class="w-12 shrink-0 tabular-nums text-xs text-gray-500 dark:text-gray-400">
                            {{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}:00
                        </span>
                        <span class="h-2 rounded bg-primary-500"
                              style="width: {{ (int) round($count * 100 / $max) }}%; min-width: 2px;"></span>
                        <span class="tabular-nums text-xs text-gray-600 dark:text-gray-300">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
