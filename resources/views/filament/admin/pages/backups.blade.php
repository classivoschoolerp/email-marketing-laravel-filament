<x-filament-panels::page>
    @php($manager = $this->manager())
    @php($backups = $this->backups())

    <div class="space-y-6">
        <x-filament::section :heading="__('ops.backups.schedule_heading')">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.backups.directory') }}</dt>
                    <dd class="font-mono break-all">{{ $manager->directory() }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.backups.retention') }}</dt>
                    <dd>
                        @if ($manager->retentionDays() === 0)
                            {{ __('ops.backups.retention_forever') }}
                        @else
                            {{ __('ops.backups.retention_days', ['days' => $manager->retentionDays()]) }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.backups.uploads') }}</dt>
                    <dd>{{ $manager->includeUploads() ? __('ops.dashboard.enabled') : __('ops.dashboard.disabled') }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ops.backups.total_size') }}</dt>
                    <dd>{{ $this->formatBytes($manager->totalBytes()) }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('ops.backups.schedule_note') }}</p>

            @unless ($manager->zipSupported())
                <p class="mt-2 text-xs text-warning-600">{{ __('ops.backups.zip_unavailable') }}</p>
            @endunless
        </x-filament::section>

        <x-filament::section :heading="__('ops.backups.files_heading')">
            @if ($backups === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('ops.backups.empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">{{ __('ops.backups.file') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.backups.kind') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.backups.size') }}</th>
                                <th class="py-2 pr-4">{{ __('ops.backups.modified') }}</th>
                                <th class="py-2">{{ __('ops.backups.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($backups as $backup)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $backup['name'] }}</td>
                                    <td class="py-2 pr-4">{{ __('ops.backups.kinds.'.$backup['type']) }}</td>
                                    <td class="py-2 pr-4">{{ $this->formatBytes($backup['size']) }}</td>
                                    <td class="py-2 pr-4">{{ $backup['modified_at'] }}</td>
                                    <td class="py-2 space-x-2">
                                        <button type="button" wire:click="download('{{ $backup['name'] }}')"
                                                class="text-xs font-medium text-primary-600 hover:underline">
                                            {{ __('ops.backups.download') }}
                                        </button>
                                        <button type="button" wire:click="deleteBackup('{{ $backup['name'] }}')"
                                                wire:confirm="{{ __('ops.backups.confirm_delete') }}"
                                                class="text-xs font-medium text-danger-600 hover:underline">
                                            {{ __('ops.backups.delete') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('ops.backups.restore.heading')">
            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">{{ __('ops.backups.restore.intro') }}</p>

            <ol class="space-y-3">
                @foreach ($this->restoreInstructions() as $step)
                    <li>
                        <div class="text-sm font-medium">{{ $step['title'] }}</div>
                        <pre class="mt-1 overflow-x-auto rounded-lg bg-gray-950/5 p-3 font-mono text-xs dark:bg-white/5">{{ $step['command'] }}</pre>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    </div>
</x-filament-panels::page>
