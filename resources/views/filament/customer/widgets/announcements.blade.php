@php($announcements = $this->announcements())

<x-filament-widgets::widget @class(['hidden' => $announcements->isEmpty()])>
    @if ($announcements->isNotEmpty())
        <div class="space-y-3">
            @foreach ($announcements as $announcement)
                <div @class([
                    'rounded-xl p-4 ring-1',
                    'bg-info-50 ring-info-600/20 dark:bg-info-400/10' => $announcement->level === \App\Models\Announcement::LEVEL_INFO,
                    'bg-warning-50 ring-warning-600/20 dark:bg-warning-400/10' => $announcement->level === \App\Models\Announcement::LEVEL_WARNING,
                    'bg-danger-50 ring-danger-600/20 dark:bg-danger-400/10' => $announcement->level === \App\Models\Announcement::LEVEL_DANGER,
                ])>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $announcement->title }}
                            </h3>
                            <div class="prose prose-sm mt-1 max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">
                                {!! $announcement->body !!}
                            </div>
                        </div>
                        @if ($announcement->dismissible)
                            <button type="button"
                                    wire:click="dismiss({{ $announcement->id }})"
                                    class="shrink-0 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
                                {{ __('dashboards.announcements.widget.dismiss') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
