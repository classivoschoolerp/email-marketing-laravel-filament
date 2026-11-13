<x-filament-panels::page>
    {{-- GrapesJS campaign content builder (spec §2.3.3, D-11). Reuses the
         WP-10 template-builder JS unchanged: the script reads its config —
         endpoints, content, merge tags, i18n — from the JSON tag below and
         mounts on the same element ids. wire:ignore keeps Livewire from
         morphing the editor DOM. --}}
    <script type="application/json" id="template-builder-config">@json($builderConfig)</script>

    <div wire:ignore class="rounded-xl overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900">
        <div id="template-builder-toolbar" class="flex flex-wrap items-center gap-2 border-b border-gray-200 p-2 dark:border-gray-700"></div>
        <div id="template-builder" style="min-height: 75vh;"></div>
    </div>

    @vite('resources/js/template-builder.js')
</x-filament-panels::page>
