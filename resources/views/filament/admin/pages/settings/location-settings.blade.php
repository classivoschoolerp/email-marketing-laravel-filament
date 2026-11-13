<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    {{-- F-3.23-002: uploaded MaxMind database status --}}
    @php($status = $this->databaseStatus())

    @if ($status !== null)
        <x-filament::section :heading="__('settings.location.database.heading')">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('settings.location.database.path') }}
                    </dt>
                    <dd class="font-mono break-all">{{ $status['path'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('settings.location.database.size') }}
                    </dt>
                    <dd>
                        @if ($status['exists'])
                            {{ number_format($status['size'] / 1048576, 1) }} MB
                        @else
                            <span class="text-danger-600">{{ __('settings.location.database.missing') }}</span>
                        @endif
                    </dd>
                </div>
                @if ($status['info'] !== null)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('settings.location.database.edition') }}
                        </dt>
                        <dd>{{ $status['info']['type'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('settings.location.database.built') }}
                        </dt>
                        <dd>{{ $status['info']['build_epoch'] ? \Illuminate\Support\Carbon::createFromTimestamp($status['info']['build_epoch'])->toDateString() : '—' }}</dd>
                    </div>
                @endif
            </dl>

            <div class="mt-4">
                <x-filament::button color="danger" size="sm" wire:click="deleteDatabase">
                    {{ __('settings.location.actions.remove_database') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{-- F-3.23-003: test lookup tool --}}
    <x-filament::section :heading="__('settings.location.test.heading')">
        <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
            {{ __('settings.location.test.description') }}
        </p>

        @unless ($this->providerConfigured())
            <p class="mb-3 text-sm text-warning-600">
                {{ __('settings.location.test.provider_not_configured') }}
            </p>
        @endunless

        <div class="flex flex-wrap items-end gap-3">
            <div class="grow">
                <label for="location-test-ip" class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">
                    {{ __('settings.location.test.ip_label') }}
                </label>
                <input
                    id="location-test-ip"
                    type="text"
                    wire:model="testIp"
                    placeholder="8.8.8.8"
                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                />
            </div>

            <x-filament::button wire:click="runTestLookup" wire:loading.attr="disabled">
                {{ __('settings.location.test.run') }}
            </x-filament::button>
        </div>

        @if ($testError !== null)
            <p class="mt-4 text-sm text-danger-600">{{ $testError }}</p>
        @endif

        @if ($testResult !== null)
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($testResult as $key => $value)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <th class="w-48 py-2 pr-4 text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('settings.location.test.keys.'.$key) }}
                                </th>
                                <td class="py-2 font-mono">{{ $value === null || $value === '' ? '—' : $value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
