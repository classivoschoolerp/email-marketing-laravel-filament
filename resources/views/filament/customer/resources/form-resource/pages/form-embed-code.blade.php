{{-- §2.7 embed codes (F-2.7-011): three variants + a live preview. --}}
<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-6">
            @foreach ([
                ['key' => 'script', 'code' => $this->scriptSnippet()],
                ['key' => 'iframe', 'code' => $this->iframeSnippet()],
                ['key' => 'html', 'code' => $this->htmlSnippet()],
            ] as $variant)
                <x-filament::section>
                    <x-slot name="heading">{{ __('forms.embed.'.$variant['key'].'_title') }}</x-slot>
                    <x-slot name="description">{{ __('forms.embed.'.$variant['key'].'_help') }}</x-slot>

                    <div x-data="{ copied: false }">
                        <pre class="max-h-64 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-relaxed text-gray-100"
                             x-ref="code{{ $variant['key'] }}">{{ $variant['code'] }}</pre>

                        <x-filament::button
                            size="sm"
                            class="mt-3"
                            x-on:click="navigator.clipboard.writeText($refs.code{{ $variant['key'] }}.innerText); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-show="! copied">{{ __('forms.embed.copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('forms.embed.copied') }}</span>
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section>
            <x-slot name="heading">{{ __('forms.embed.preview_title') }}</x-slot>
            <x-slot name="description">{{ __('forms.embed.preview_help') }}</x-slot>

            <iframe src="{{ $this->previewUrl() }}"
                    class="h-[640px] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
                    title="{{ __('forms.embed.preview_title') }}"></iframe>
        </x-filament::section>
    </div>
</x-filament-panels::page>
