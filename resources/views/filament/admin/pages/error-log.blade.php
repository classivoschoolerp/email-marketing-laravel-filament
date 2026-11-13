<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap items-end gap-4">
        <div>
            <label for="log-level" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('reports.admin.error_log.severity') }}
            </label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="log-level" wire:model.live="level">
                    <option value="all">{{ __('reports.admin.error_log.all_levels') }}</option>
                    @foreach ($this->levels() as $level)
                        <option value="{{ $level }}">{{ $level }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div>
            <label for="log-entries" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('reports.admin.error_log.entries') }}
            </label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="log-entries" wire:model.live="entries">
                    @foreach ([50, 100, 250, 500] as $count)
                        <option value="{{ $count }}">{{ $count }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <x-filament::button color="gray" icon="heroicon-m-arrow-path" wire:click="$refresh">
            {{ __('reports.admin.error_log.refresh') }}
        </x-filament::button>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('reports.admin.error_log.file_line', [
                'path' => $this->logPath(),
                'size' => app(\App\Services\Reports\SystemInfoService::class)->formatBytes($this->logSize()),
            ]) }}
        </p>
    </div>

    @php($entries = $this->logEntries())

    @if ($entries === [])
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.admin.error_log.empty') }}</p>
        </x-filament::section>
    @else
        <div class="space-y-2">
            @foreach ($entries as $entry)
                <details class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-3 text-sm">
                        <x-filament::badge :color="$this->levelColor($entry['level'])">
                            {{ $entry['level'] }}
                        </x-filament::badge>
                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $entry['timestamp'] }}</span>
                        <span class="min-w-0 flex-1 truncate text-gray-950 dark:text-white">{{ $entry['message'] }}</span>
                    </summary>
                    <pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ $entry['message'] }}@if ($entry['context'] !== '')

{{ $entry['context'] }}@endif</pre>
                </details>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
