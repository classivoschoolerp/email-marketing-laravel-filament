{{-- Revision history modal (spec §2.5.4 F-2.5.4-008): newest first, restore per row.
     Rendered inside the page Livewire component, so wire:click reaches
     HasTemplateRevisions::restoreRevision(). --}}
<div class="space-y-2">
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('templates.revisions.current_note') }}</p>

    @forelse ($revisions as $revision)
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-sm">
                <span class="font-medium">
                    {{ __('templates.revisions.saved_at', ['time' => $revision->created_at?->diffForHumans() ?? '—']) }}
                </span>
                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ $revision->created_at?->toDayDateTimeString() }}
                </span>
            </div>

            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-m-arrow-uturn-left"
                wire:click="restoreRevision({{ $revision->id }})"
            >
                {{ __('templates.revisions.restore') }}
            </x-filament::button>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('templates.revisions.empty') }}</p>
    @endforelse
</div>
