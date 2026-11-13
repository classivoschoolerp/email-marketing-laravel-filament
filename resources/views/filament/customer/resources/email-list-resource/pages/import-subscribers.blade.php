<x-filament-panels::page>
    <form wire:submit="startImport" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-m-arrow-up-tray">
            {{ __('imports.page.start') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
