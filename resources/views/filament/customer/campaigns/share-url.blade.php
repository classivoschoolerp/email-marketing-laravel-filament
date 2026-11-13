{{-- Public share-report URL modal (spec §2.3.4 F-2.3.4-020). --}}
<div x-data class="space-y-3">
    <code class="block break-all rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200">{{ $url }}</code>
    <div class="flex gap-2">
        <x-filament::button
            size="sm"
            color="gray"
            icon="heroicon-m-clipboard"
            x-on:click="navigator.clipboard && navigator.clipboard.writeText(@js($url))"
        >
            {{ __('campaigns.notifications.share_url_copied') }}
        </x-filament::button>
        <x-filament::button
            size="sm"
            tag="a"
            href="{{ $url }}"
            target="_blank"
            icon="heroicon-m-arrow-top-right-on-square"
        >
            {{ __('campaigns.actions.share_report') }}
        </x-filament::button>
    </div>
</div>
