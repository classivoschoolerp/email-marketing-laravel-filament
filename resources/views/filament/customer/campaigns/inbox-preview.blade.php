{{-- §2.3.5 F-2.3.5-006 — inbox rendering preview across 50+ clients.

     Shows the latest run for this campaign. Screenshots are the provider's own
     URLs (see the migration for why they are not mirrored locally), so the
     grid degrades to a link when an image has expired rather than pretending
     the client was never rendered. --}}
<div class="space-y-3">
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('inbox_preview.scope') }}</p>

    @if (! $configured)
        <p class="rounded-md bg-warning-50 px-3 py-2 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            {{ __('inbox_preview.errors.not_configured') }}
        </p>
    @elseif ($run === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('inbox_preview.none') }}</p>
    @else
        @php($screens = $run->screens())
        @php($done = collect($screens)->where('state', '!=', 'processing')->count())

        <p class="text-sm">
            <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('inbox_preview.provider') }}
            </span>
            {{ __('inbox_preview.providers.'.$run->provider) }}
            —
            @if ($run->status === \App\Models\InboxPreviewRun::STATUS_FAILED)
                <span class="text-danger-600">{{ __('inbox_preview.failed', ['error' => $run->error]) }}</span>
            @elseif ($run->status === \App\Models\InboxPreviewRun::STATUS_COMPLETED)
                {{ __('inbox_preview.completed', [
                    'count' => count($screens),
                    'date' => optional($run->completed_at)->toDayDateTimeString(),
                ]) }}
            @else
                {{ __('inbox_preview.pending', ['done' => $done, 'total' => max(count($screens), 1)]) }}
            @endif
        </p>

        @if ($screens !== [])
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                @foreach ($screens as $screen)
                    <div class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                        <p class="truncate text-xs font-medium">{{ $screen->label }}</p>
                        <p class="truncate text-[10px] text-gray-500 dark:text-gray-400">
                            {{ $screen->os }} · {{ __('inbox_preview.state.'.$screen->state) }}
                        </p>

                        @if ($screen->thumbnailUrl !== null || $screen->imageUrl !== null)
                            <a href="{{ $screen->imageUrl ?? $screen->thumbnailUrl }}" target="_blank" rel="noopener noreferrer">
                                <img
                                    src="{{ $screen->thumbnailUrl ?? $screen->imageUrl }}"
                                    alt="{{ $screen->label }}"
                                    loading="lazy"
                                    class="mt-1 w-full rounded border border-gray-100 dark:border-gray-800"
                                >
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('inbox_preview.expired_note') }}</p>
        @endif
    @endif
</div>
