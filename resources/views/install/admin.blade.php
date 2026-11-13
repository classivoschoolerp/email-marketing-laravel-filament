<x-install-layout :step="$step" :title="__('install.admin.title')">
    <h2 class="text-lg font-semibold">{{ __('install.admin.title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('install.admin.intro') }}</p>

    <form method="POST" action="{{ route('install.admin.store') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">{{ __('install.admin.name') }}</label>
            <input id="name" name="name" type="text" required value="{{ old('name') }}"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">{{ __('install.admin.email') }}</label>
            <input id="email" name="email" type="email" required value="{{ old('email') }}"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">{{ __('install.admin.password') }}</label>
            <input id="password" name="password" type="password" required
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
            <p class="mt-1 text-xs text-gray-500">{{ __('install.admin.password_hint') }}</p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">{{ __('install.admin.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
        </div>

        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700">
            {{ __('install.continue') }}
        </button>
    </form>
</x-install-layout>
