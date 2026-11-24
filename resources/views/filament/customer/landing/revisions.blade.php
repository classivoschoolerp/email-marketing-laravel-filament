{{-- Landing-page revision history (spec §2.6 F-2.6-005): newest first,
     restore per row. Rendered inside the page Livewire component, so
     wire:click reaches HasLandingRevisions::restoreRevision(). --}}
<div class="space-y-2">
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('landing.revisions.current_note') }}</p>

    @forelse ($revisions as $revision)
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-sm">
                <span class="font-medium">
                    {{ __('landing.revisions.saved_at', ['time' => $revision->created_at?->diffForHumans() ?? '—']) }}
                </span>
                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ $revision->created_at?->toDayDateTimeString() }}
                </span>
                @if ($revision->landing_page_variant_id)
                    <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-800">
                        {{ $revision->variant?->name }}
                    </span>
                @endif
            </div>

            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-m-arrow-uturn-left"
                wire:click="restoreRevision({{ $revision->id }})"
            >
                {{ __('landing.revisions.restore') }}
            </x-filament::button>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('landing.revisions.empty') }}</p>
    @endforelse
</div>
