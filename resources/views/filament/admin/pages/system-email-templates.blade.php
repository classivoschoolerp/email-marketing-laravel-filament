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
                wire:confirm="{{ __('system-emails.page.reset_confirm') }}"
            >
                {{ __('system-emails.page.reset') }}
            </x-filament::button>
        </div>
    </form>

    {{-- Every §3.15 key at a glance (F-3.15-016). --}}
    <x-filament::section :heading="__('system-emails.page.overview')" collapsible collapsed>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-1 pr-4 font-medium">{{ __('system-emails.page.overview_key') }}</th>
                    <th class="py-1 font-medium">{{ __('system-emails.page.overview_state') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->overview as $key => $row)
                    <tr wire:key="overview-{{ $key }}">
                        <td class="py-1 pr-4">{{ $row['label'] }}</td>
                        <td class="py-1">
                            <x-filament::badge :color="$row['customized'] ? 'warning' : 'gray'" class="inline-flex">
                                {{ $row['customized'] ? __('system-emails.page.state_customized') : __('system-emails.page.state_default') }}
                            </x-filament::badge>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
