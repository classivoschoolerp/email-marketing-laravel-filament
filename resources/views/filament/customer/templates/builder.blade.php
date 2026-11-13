<x-filament-panels::page>
    {{-- GrapesJS drag-and-drop builder (spec §2.5.4, D-11). The editor is
         mounted by resources/js/template-builder.js (Vite input); config —
         endpoints, content, merge tags, fonts, i18n — is passed via the JSON
         script tag below. wire:ignore keeps Livewire from morphing the editor
         DOM. --}}
    <script type="application/json" id="template-builder-config">@json($builderConfig)</script>

    {{-- §4.8 F-4.8-001: GrapesJS mounts its own three-column chrome (blocks /
         canvas / style manager) inside #template-builder. Below 640px those
         panels overlap the canvas and the editor is unusable, so the honest
         answer on a phone is to say so and offer the basic block builder,
         which IS responsive — not to ship a broken drag-and-drop surface. --}}
    <div class="sm:hidden">
        <x-filament::section :heading="__('analytics.mobile.builder_heading')">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.mobile.builder_body') }}</p>
        </x-filament::section>
    </div>

    <div wire:ignore class="hidden rounded-xl overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900 sm:block">
        <div id="template-builder-toolbar" class="flex flex-wrap items-center gap-2 border-b border-gray-200 p-2 dark:border-gray-700"></div>
        <div id="template-builder" style="min-height: 75vh;"></div>
    </div>

    @vite('resources/js/template-builder.js')
</x-filament-panels::page>
