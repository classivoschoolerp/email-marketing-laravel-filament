{{-- §2.3.10 F-2.3.10-003 — group-level aggregate reporting. --}}
@if ($aggregate['campaigns'] === 0)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.comparison.none') }}</p>
@else
    <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
        {{ __('reports.comparison.basis', ['count' => $aggregate['campaigns']]) }}
    </p>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($aggregate['totals'] as $key => $value)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('reports.stats.'.$key) }}
                </div>
                <div class="text-lg font-semibold">{{ number_format($value) }}</div>
            </div>
        @endforeach

        @foreach ($aggregate['rates'] as $key => $value)
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('reports.stats.'.$key) }}
                </div>
                <div class="text-lg font-semibold">{{ $value }}%</div>
            </div>
        @endforeach
    </div>
@endif
