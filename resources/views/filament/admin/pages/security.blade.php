<x-filament-panels::page>
    {{-- Password change (F-3.2-004 — force-password-reset target) --}}
    <x-filament::section :heading="__('admin.security.section_password')">
        @if ($this->mustChangePassword())
            <div class="mb-4 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm" data-testid="force-password-reset-notice">
                {{ __('admin.security.password_forced_notice') }}
            </div>
        @endif

        <div class="grid gap-4 sm:max-w-md">
            <div>
                <label class="block text-sm font-medium" for="passwordCurrent">
                    {{ __('admin.security.password_current') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="password" id="passwordCurrent" wire:model="passwordCurrent" />
                </x-filament::input.wrapper>
                @error('passwordCurrent')
                    <p class="text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium" for="passwordNew">
                    {{ __('admin.security.password_new') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="password" id="passwordNew" wire:model="passwordNew" />
                </x-filament::input.wrapper>
                @error('passwordNew')
                    <p class="text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium" for="passwordNewConfirmation">
                    {{ __('admin.security.password_new_confirmation') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input type="password" id="passwordNewConfirmation" wire:model="passwordNewConfirmation" />
                </x-filament::input.wrapper>
            </div>

            <div>
                <x-filament::button wire:click="changePassword">
                    {{ __('admin.security.password_change') }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- Two-factor authentication (D-35 — admin enrolment) --}}
    <x-filament::section :heading="__('auth-module.security.section_twofa')">
        @if ($this->admin()->twofa_enabled)
            <p class="text-sm text-success-600">{{ __('auth-module.twofa.status_enabled') }}</p>

            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                <div class="space-y-3">
                    <label class="block text-sm font-medium" for="currentPassword">
                        {{ __('auth-module.twofa.current_password') }}
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="password" id="currentPassword" wire:model="currentPassword" />
                    </x-filament::input.wrapper>
                    @error('currentPassword')
                        <p class="text-sm text-danger-600">{{ $message }}</p>
                    @enderror

                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="danger" wire:click="disableTwoFactor">
                            {{ __('auth-module.twofa.disable') }}
                        </x-filament::button>
                        <x-filament::button color="gray" wire:click="regenerateRecoveryCodes">
                            {{ __('auth-module.twofa.regenerate') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @elseif ($pendingSecret !== null)
            <p class="text-sm">{{ __('auth-module.twofa.scan_qr') }}</p>

            <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="rounded-lg bg-white p-2 shadow">{!! $this->getQrCodeSvg() !!}</div>

                <div class="space-y-3">
                    <p class="break-all text-xs text-gray-500">
                        {{ __('auth-module.twofa.secret_key', ['secret' => $pendingSecret]) }}
                    </p>

                    <label class="block text-sm font-medium" for="confirmationCode">
                        {{ __('auth-module.twofa.code') }}
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" id="confirmationCode" wire:model="confirmationCode" autocomplete="one-time-code" />
                    </x-filament::input.wrapper>
                    @error('confirmationCode')
                        <p class="text-sm text-danger-600">{{ $message }}</p>
                    @enderror

                    <div class="flex flex-wrap gap-3">
                        <x-filament::button wire:click="confirmTwoFactor">
                            {{ __('auth-module.twofa.confirm_setup') }}
                        </x-filament::button>
                        <x-filament::button color="gray" wire:click="cancelTwoFactorSetup">
                            {{ __('auth-module.twofa.cancel_setup') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500">{{ __('auth-module.twofa.status_disabled') }}</p>

            <x-filament::button class="mt-4" wire:click="startTwoFactorSetup">
                {{ __('auth-module.twofa.enable') }}
            </x-filament::button>
        @endif

        @if (count($freshRecoveryCodes) > 0)
            <div class="mt-6 rounded-lg border border-warning-300 bg-warning-50 p-4">
                <h3 class="font-semibold">{{ __('auth-module.twofa.recovery_codes_title') }}</h3>
                <p class="mt-1 text-sm">{{ __('auth-module.twofa.recovery_codes_hint') }}</p>
                <ul class="mt-3 grid grid-cols-1 gap-1 font-mono text-sm sm:grid-cols-2" data-testid="recovery-codes">
                    @foreach ($freshRecoveryCodes as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-filament::section>

    {{-- Login history (§3.2 F-3.2-006) --}}
    <x-filament::section :heading="__('auth-module.security.section_history')">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
