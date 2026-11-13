<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('i18n.editor.save') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
