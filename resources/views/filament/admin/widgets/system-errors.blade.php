<x-filament-widgets::widget>
    <x-filament::section :heading="__('ops.errors.heading')" wire:poll.30s>
        <x-slot name="description">
            @if (! $enabled)
                {{ __('ops.errors.disabled') }}
            @elseif ($newCount > 0)
                <span class="text-danger-600">{{ __('ops.errors.new_since_last_scan', ['count' => $newCount]) }}</span>
            @else
                {{ __('ops.errors.none_new', ['level' => $minimumLevel]) }}
            @endif
        </x-slot>

        @if (count($entries) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('ops.errors.empty') }}</p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach ($entries as $entry)
                    <li class="border-b border-gray-100 pb-2 last:border-0 dark:border-white/5">
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-danger-50 px-1.5 py-0.5 text-xs font-semibold text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                                {{ $entry['level'] }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $entry['timestamp'] }}</span>
                        </div>
                        <div class="mt-1 break-all font-mono text-xs">{{ \Illuminate\Support\Str::limit($entry['message'], 160) }}</div>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-3">
            <a href="{{ $logUrl }}" class="text-xs font-medium text-primary-600 hover:underline">
                {{ __('ops.errors.view_log') }}
            </a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
