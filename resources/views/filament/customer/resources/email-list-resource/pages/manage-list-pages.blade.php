<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit">
                {{ __('lists.pages.save') }}
            </x-filament::button>

            <x-filament::button
                type="button"
                color="danger"
                outlined
                wire:click="resetToDefault"
                wire:confirm="{{ __('lists.pages.reset_confirm') }}"
            >
                {{ __('lists.pages.reset') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
