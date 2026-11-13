{{-- Live upgrade manager (spec §6.2 F-6.2-001/003/007, WP-41).
     Version banner + changelog, the pre-upgrade check board, the protected
     paths that make "no data loss" verifiable, and the upgrade history. --}}
<x-filament-panels::page>

    {{-- ---------------------------------------------- F-6.2-001 versions --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('upgrade.title') }}</x-slot>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('upgrade.version.current') }}
                </div>
                <div class="mt-1 font-mono text-lg">{{ $result['current'] }}</div>
            </div>

            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('upgrade.version.latest') }}
                </div>
                <div class="mt-1 font-mono text-lg">
                    {{ $release['version'] ?? '—' }}
                </div>
            </div>

            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('upgrade.version.channel') }}
                </div>
                <div class="mt-1">
                    <x-filament::badge
                        :color="match ($result['status']) {
                            'available' => 'warning',
                            'current' => 'success',
                            default => 'gray',
                        }"
                        class="inline-flex"
                    >
                        {{ __('upgrade.status.'.$result['status']) }}
                    </x-filament::badge>
                    <span class="ml-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $channel }}</span>
                </div>
            </div>
        </div>

        @if (! empty($result['message']))
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ $result['message'] }}</p>
        @endif

        @if ($upgradeAvailable && ! $installable)
            <p class="mt-4 text-sm text-warning-600 dark:text-warning-400">
                {{ __('upgrade.release.not_installable') }}
            </p>
        @endif

        @if (! empty($release['released_at']) || ! empty($release['size']))
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                @if (! empty($release['released_at']))
                    {{ __('upgrade.version.released_at') }}: {{ $release['released_at'] }}
                @endif
                @if (! empty($release['size']))
                    · {{ __('upgrade.version.size') }}: {{ $release['size'] }}
                @endif
                @if (! empty($release['mandatory']))
                    · {{ __('upgrade.version.mandatory') }}
                @endif
            </p>
        @endif
    </x-filament::section>

    {{-- ---------------------------------------------- F-6.2-001 changelog --}}
    @if ($upgradeAvailable)
        <x-filament::section collapsible>
            <x-slot name="heading">{{ __('upgrade.release.changelog') }}</x-slot>

            @if (trim((string) ($release['changelog'] ?? '')) === '')
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('upgrade.release.no_changelog') }}</p>
            @else
                {{-- Publisher-supplied text: escaped, never rendered as HTML. --}}
                <pre class="whitespace-pre-wrap text-sm leading-relaxed">{{ $release['changelog'] }}</pre>
            @endif
        </x-filament::section>
    @endif

    {{-- ------------------------------------------------- F-6.2-003 checks --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('upgrade.checks.heading') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($checks as $check)
                        <tr>
                            <td class="py-2 pr-4 whitespace-nowrap">
                                {{ __('upgrade.checks.'.$check['key'].'.label') }}
                            </td>
                            <td class="py-2 pr-4">
                                <x-filament::badge
                                    :color="match ($check['status']) {
                                        'pass' => 'success',
                                        'fail' => 'danger',
                                        default => 'warning',
                                    }"
                                    class="inline-flex"
                                >
                                    {{ __('upgrade.checks.'.$check['status']) }}
                                </x-filament::badge>
                            </td>
                            <td class="py-2 pr-4 text-xs text-gray-500 dark:text-gray-400">
                                {{ $check['blocking'] ? __('upgrade.checks.blocking') : __('upgrade.checks.advisory') }}
                            </td>
                            <td class="py-2 text-gray-600 dark:text-gray-300">{{ $check['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ------------------------------------- F-6.2-006 protected paths ---}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('upgrade.confirm.heading') }}</x-slot>

        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('upgrade.confirm.body') }}</p>

        <ul class="mt-3 grid gap-1 sm:grid-cols-2">
            @foreach ($protectedPaths as $path)
                <li class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $path }}</li>
            @endforeach
        </ul>
    </x-filament::section>

    {{-- ---------------------------------------------- F-6.2-007 history ---}}
    <x-filament::section>
        <x-slot name="heading">{{ __('upgrade.history.heading') }}</x-slot>

        @if ($runs->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('upgrade.history.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">{{ __('upgrade.history.started_at') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('upgrade.history.versions') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('upgrade.history.status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('upgrade.history.actor') }}</th>
                            <th class="py-2 font-medium">{{ __('upgrade.steps.heading') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($runs as $run)
                            <tr>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $run->started_at }}</td>
                                <td class="py-2 pr-4 font-mono text-xs whitespace-nowrap">
                                    {{ $run->from_version }} → {{ $run->to_version ?? '—' }}
                                    @if ($run->dry_run)
                                        <span class="ml-1 text-gray-400">({{ __('upgrade.dry_run.badge') }})</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    <x-filament::badge
                                        :color="match ($run->status) {
                                            'success' => 'success',
                                            'failed', 'running' => 'danger',
                                            'rolled_back', 'blocked' => 'warning',
                                            default => 'gray',
                                        }"
                                        class="inline-flex"
                                    >
                                        {{ __('upgrade.history.statuses.'.$run->status) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2 pr-4 text-xs">
                                    {{ $run->user?->name ?? __('upgrade.history.system') }}
                                </td>
                                <td class="py-2 text-xs text-gray-500 dark:text-gray-400">
                                    <ul class="space-y-0.5">
                                        @foreach ($run->stepRows() as $step)
                                            @if ($step['status'] !== 'pending')
                                                <li>
                                                    <span class="font-medium">{{ __('upgrade.steps.labels.'.$step['key']) }}</span>
                                                    — {{ __('upgrade.steps.status.'.$step['status']) }}
                                                    @if ($step['detail'] !== '')
                                                        : {{ $step['detail'] }}
                                                    @endif
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>

                                    @if (($run->notes ?? '') !== '')
                                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $run->notes }}</p>
                                    @endif

                                    @if (($run->error ?? '') !== '')
                                        <p class="mt-1 text-danger-600 dark:text-danger-400">{{ $run->error }}</p>
                                    @endif

                                    @if ($run->snapshot_path && ! $run->dry_run)
                                        <button
                                            type="button"
                                            wire:click="rollbackRun({{ $run->id }})"
                                            class="mt-1 text-primary-600 underline dark:text-primary-400"
                                        >
                                            {{ __('upgrade.actions.rollback') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

</x-filament-panels::page>
