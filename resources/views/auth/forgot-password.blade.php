<x-guest-layout :title="__('auth-module.reset.request_title')">
    <h1 class="text-xl font-semibold">{{ __('auth-module.reset.request_title') }}</h1>
    <p class="mt-2 text-sm text-gray-600">{{ __('auth-module.reset.request_body') }}</p>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email" class="block text-sm font-medium">{{ __('auth-module.reset.email') }}</label>
            <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
            {{ __('auth-module.reset.send_link') }}
        </button>

        <p class="text-center text-sm text-gray-500">
            <a href="{{ url('/customer/login') }}" class="font-medium text-emerald-600 hover:underline">{{ __('auth-module.reset.back_to_login') }}</a>
        </p>
    </form>
</x-guest-layout>
