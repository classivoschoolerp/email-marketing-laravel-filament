{{-- §2.15.3 notification preferences (WP-39) --}}
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('notifications.preferences.intro') }}
    </p>

    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
