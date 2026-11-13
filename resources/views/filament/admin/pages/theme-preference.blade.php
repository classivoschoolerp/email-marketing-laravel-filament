{{-- Per-user theme selection (spec §6.3 F-6.3-002, WP-38). --}}
<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            {{ __('settings.save') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
