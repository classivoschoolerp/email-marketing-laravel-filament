<x-filament-panels::page>
    <form wire:submit="saveProfile" class="space-y-6">
        {{ $this->profileForm }}

        <x-filament::button type="submit">
            {{ __('auth-module.profile.save') }}
        </x-filament::button>
    </form>

    <form wire:submit="savePassword" class="space-y-6">
        {{ $this->passwordForm }}

        <x-filament::button type="submit" color="warning">
            {{ __('auth-module.profile.change_password') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
