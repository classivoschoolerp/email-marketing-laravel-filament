<x-filament-panels::page>
    {{-- Landing template gallery (spec §2.6 F-2.6-003).

         The preview is the design's REAL markup rendered inside a sandboxed,
         scaled iframe with the landing stylesheet — a screenshot would need
         headless Chrome (F-2.5.5-003, not installed by design), and a
         hand-drawn thumbnail would be a picture of something the customer is
         not actually getting. srcdoc + sandbox means the preview cannot run
         scripts or navigate the panel. --}}
    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($designs as $design)
            <div @class([
                'flex flex-col overflow-hidden rounded-xl border bg-white dark:bg-gray-900',
                'border-primary-500 ring-1 ring-primary-500' => $design['key'] === $currentKey,
                'border-gray-200 dark:border-gray-700' => $design['key'] !== $currentKey,
            ])>
                <div class="h-56 overflow-hidden bg-gray-50 dark:bg-gray-800">
                    <iframe
                        class="pointer-events-none origin-top-left"
                        style="width:1080px;height:840px;transform:scale(.32);border:0"
                        sandbox
                        title="{{ $design['name'] }}"
                        srcdoc="{{ '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'.$previewCss.'</style></head><body class="lp-body">'.$design['html'].'</body></html>' }}"
                    ></iframe>
                </div>

                <div class="flex flex-1 flex-col gap-2 p-4">
                    <h3 class="text-base font-semibold">{{ $design['name'] }}</h3>
                    <p class="flex-1 text-sm text-gray-500 dark:text-gray-400">{{ $design['description'] }}</p>

                    <x-filament::button
                        size="sm"
                        wire:click="applyDesign('{{ $design['key'] }}')"
                        icon="heroicon-m-check"
                    >
                        {{ __('landing.actions.use_design') }}
                    </x-filament::button>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
