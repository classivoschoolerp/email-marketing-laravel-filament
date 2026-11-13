<x-guest-layout :title="__('auth-module.twofa.challenge_title')">
    <h1 class="text-xl font-semibold">{{ __('auth-module.twofa.challenge_title') }}</h1>
    <p class="mt-2 text-sm text-gray-600">{{ __('auth-module.twofa.challenge_body') }}</p>

    <form method="POST" action="{{ route('customer.two-factor.verify') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="code" class="block text-sm font-medium">{{ __('auth-module.twofa.code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('code')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
            {{ __('auth-module.twofa.verify') }}
        </button>
    </form>
</x-guest-layout>
