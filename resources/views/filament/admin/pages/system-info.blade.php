<x-filament-panels::page>
    @php($info = $this->info())
    @php($disk = $info->diskSpace())

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-filament::section :heading="__('reports.admin.system_info.versions')">
            <dl class="space-y-2 text-sm">
                @foreach ($info->versions() as $key => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('reports.admin.system_info.keys.'.$key) }}</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
                @foreach ($info->drivers() as $key => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('reports.admin.system_info.drivers.'.$key) }}</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('reports.admin.system_info.limits')">
            <dl class="space-y-2 text-sm">
                @foreach ($info->limits() as $key => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $key }}</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('reports.admin.system_info.storage')">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('reports.admin.system_info.disk_free') }}</dt>
                    <dd class="font-medium text-gray-950 dark:text-white">
                        {{ $info->formatBytes($disk['free']) }} / {{ $info->formatBytes($disk['total']) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('reports.admin.system_info.db_size') }}</dt>
                    <dd class="font-medium text-gray-950 dark:text-white">{{ $info->formatBytes($info->databaseSizeBytes()) }}</dd>
                </div>
                @foreach ($info->storageUsage() as $dir => $bytes)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $dir }}</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $info->formatBytes($bytes) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        {{-- Log retention (F-3.27-015): read-only view of the logs:prune
             windows; 0 = keep forever (D-23). --}}
        <x-filament::section :heading="__('reports.admin.system_info.retention')">
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                {{ __('reports.admin.system_info.retention_hint') }}
            </p>
            <dl class="space-y-2 text-sm">
                @foreach ($info->retention() as $key => $days)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('reports.admin.system_info.retention_keys.'.$key) }}</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">
                            {{ $days > 0
                                ? __('reports.admin.system_info.days', ['days' => $days])
                                : __('reports.admin.system_info.keep_forever') }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    </div>

    <x-filament::section :heading="__('reports.admin.system_info.extensions')" class="mt-6" collapsible collapsed>
        <div class="flex flex-wrap gap-2">
            @foreach ($info->extensions() as $extension)
                <x-filament::badge color="gray">{{ $extension }}</x-filament::badge>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
