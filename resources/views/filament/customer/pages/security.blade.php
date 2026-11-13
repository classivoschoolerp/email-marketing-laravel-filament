<x-filament-panels::page>
    {{-- Two-factor authentication (F-2.15.2-001) --}}
    <x-filament::section :heading="__('auth-module.security.section_twofa')">
        @if ($this->customer()->twofa_enabled)
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

    {{-- Active sessions (F-2.15.2-002) --}}
    <x-filament::section :heading="__('auth-module.security.section_sessions')">
        @if (! $this->sessionsAvailable())
            <p class="text-sm text-gray-500">{{ __('auth-module.security.sessions_unavailable') }}</p>
        @endif

        <p class="text-sm text-gray-500">{{ __('auth-module.security.sessions_hint') }}</p>

        <ul class="mt-4 divide-y divide-gray-200">
            @forelse ($this->getSessions() as $session)
                <li class="flex items-center justify-between gap-4 py-3" data-session-id="{{ $session['id'] }}">
                    <div>
                        <p class="text-sm font-medium">
                            {{ $session['ip'] ?? '—' }}
                            @if ($session['current'])
                                <span class="ml-2 rounded bg-success-100 px-1.5 py-0.5 text-xs text-success-700">
                                    {{ __('auth-module.security.this_device') }}
                                </span>
                            @endif
                        </p>
                        <p class="max-w-md truncate text-xs text-gray-500">
                            {{ $session['user_agent'] ?? __('auth-module.security.unknown_device') }}
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ __('auth-module.security.last_active', ['time' => $session['last_activity']->diffForHumans()]) }}
                        </p>
                    </div>

                    @unless ($session['current'])
                        <x-filament::button size="sm" color="danger" wire:click="revokeSession('{{ $session['id'] }}')">
                            {{ __('auth-module.security.revoke') }}
                        </x-filament::button>
                    @endunless
                </li>
            @empty
            @endforelse
        </ul>
    </x-filament::section>

    {{-- IP allowlist (F-2.15.2-004) --}}
    <x-filament::section :heading="__('auth-module.ip_allowlist.section')">
        <p class="text-sm text-gray-500">{{ __('auth-module.ip_allowlist.hint') }}</p>
        <p class="mt-1 text-xs text-gray-400">
            {{ __('auth-module.ip_allowlist.current_ip', ['ip' => $this->currentIp()]) }}
        </p>

        <div class="mt-4 space-y-3">
            @foreach ($ipAllowlist as $index => $entry)
                <div class="flex items-center gap-2" wire:key="ip-allow-{{ $index }}">
                    <x-filament::input.wrapper class="flex-1">
                        <x-filament::input type="text" wire:model="ipAllowlist.{{ $index }}"
                                           placeholder="203.0.113.4" />
                    </x-filament::input.wrapper>
                    <x-filament::button size="sm" color="gray"
                                        wire:click="$set('ipAllowlist.{{ $index }}', '')">
                        {{ __('auth-module.ip_allowlist.remove') }}
                    </x-filament::button>
                </div>
            @endforeach

            <x-filament::button size="sm" color="gray" wire:click="$push('ipAllowlist', '')">
                {{ __('auth-module.ip_allowlist.add') }}
            </x-filament::button>

            @error('ipAllowlist')
                <p class="text-sm text-danger-600">{{ $message }}</p>
            @enderror

            <x-filament::input.wrapper>
                <x-filament::input type="password" wire:model="currentPassword"
                                   :placeholder="__('auth-module.twofa.current_password')" />
            </x-filament::input.wrapper>
            @error('currentPassword')
                <p class="text-sm text-danger-600">{{ $message }}</p>
            @enderror

            <x-filament::button wire:click="saveIpAllowlist">
                {{ __('auth-module.ip_allowlist.save') }}
            </x-filament::button>
        </div>
    </x-filament::section>

    {{-- Login history (F-2.15.2-003) --}}
    <x-filament::section :heading="__('auth-module.security.section_history')">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
