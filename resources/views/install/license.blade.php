<x-install-layout :step="$step" :title="__('install.license.title')">
    <h2 class="text-lg font-semibold">{{ __('install.license.title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('install.license.intro') }}</p>

    <form method="POST" action="{{ route('install.license.store') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="license_key" class="block text-sm font-medium">{{ __('install.license.key') }}</label>
            <input id="license_key" name="license_key" type="text" value="{{ old('license_key') }}"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm font-mono">
            <p class="mt-1 text-xs text-gray-500">{{ __('install.license.self_hosted_hint') }}</p>
        </div>

        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700">
            {{ __('install.continue') }}
        </button>
    </form>
</x-install-layout>
