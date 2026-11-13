@php
    /** @var \App\Models\ImportJob $record */
    $record = $getRecord();
    $percent = $record->progressPercent();
    $eta = $record->etaSeconds();
@endphp

<div class="w-40 space-y-1 py-1">
    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
        <div
            class="h-2 rounded-full {{ $record->status === \App\Models\ImportJob::STATUS_FAILED ? 'bg-danger-500' : 'bg-primary-500' }}"
            style="width: {{ $percent ?? 0 }}%"
        ></div>
    </div>
    <div class="text-xs text-gray-500 dark:text-gray-400">
        @if ($record->total_rows !== null)
            {{ __('imports.history.progress_of', ['processed' => number_format($record->processed_rows), 'total' => number_format($record->total_rows)]) }}
        @else
            {{ __('imports.history.pending') }}
        @endif

        @if ($eta !== null)
            · {{ __('imports.history.eta', ['time' => \Carbon\CarbonInterval::seconds($eta)->cascade()->forHumans(short: true)]) }}
        @endif
    </div>
</div>
