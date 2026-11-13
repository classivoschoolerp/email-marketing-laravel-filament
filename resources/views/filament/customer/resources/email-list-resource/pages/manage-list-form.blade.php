<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('lists.embed.save') }}
        </x-filament::button>
    </form>

    <x-filament::section :heading="__('lists.embed.snippet_html')">
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lists.embed.snippet_html_help') }}</p>
        <textarea readonly rows="10" onclick="this.select()"
            class="w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-800"
        >{{ $this->htmlSnippet }}</textarea>
    </x-filament::section>

    <x-filament::section :heading="__('lists.embed.snippet_iframe')">
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lists.embed.snippet_iframe_help') }}</p>
        <textarea readonly rows="3" onclick="this.select()"
            class="w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-800"
        >{{ $this->iframeSnippet }}</textarea>
    </x-filament::section>

    <x-filament::section :heading="__('lists.embed.snippet_js')">
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">{{ __('lists.embed.snippet_js_help') }}</p>
        <textarea readonly rows="4" onclick="this.select()"
            class="w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-800"
        >{{ $this->jsSnippet }}</textarea>
    </x-filament::section>
</x-filament-panels::page>
