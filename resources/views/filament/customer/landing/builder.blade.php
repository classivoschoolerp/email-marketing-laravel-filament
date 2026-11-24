<x-filament-panels::page>
    {{-- Landing-page builder (spec §2.6 F-2.6-002, F-2.6-013).

         The SAME GrapesJS bundle as the §2.5.4 template builder — the config
         below carries mode:"landing", which switches CSS inlining off, swaps
         the email block palette for LandingBlocks and styles the canvas with
         the landing stylesheet. No new JS package; `npm run build` rebuilds
         the one entry both editors share.

         wire:ignore keeps Livewire from morphing the editor DOM. --}}
    <script type="application/json" id="template-builder-config">@json($builderConfig)</script>

    {{-- §4.8 F-4.8-001: same guard as the §2.5.4 template builder — GrapesJS'
         three-column chrome cannot be used at 375px, so say so rather than
         render an unusable canvas. --}}
    <div class="sm:hidden">
        <x-filament::section :heading="__('analytics.mobile.builder_heading')">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.mobile.builder_body') }}</p>
        </x-filament::section>
    </div>

    <div wire:ignore class="hidden rounded-xl overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900 sm:block">
        <div id="template-builder-toolbar" class="flex flex-wrap items-center gap-2 border-b border-gray-200 p-2 dark:border-gray-700"></div>
        <div id="template-builder" style="min-height: 75vh;"></div>
    </div>

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('landing.builder_page.device_hint') }}</p>

    @vite('resources/js/template-builder.js')
</x-filament-panels::page>
