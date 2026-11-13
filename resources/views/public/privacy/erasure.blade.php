{{-- WP-22 (spec §3.25.16 F-3.25.16-003 / §4.5 F-4.5-001) --}}
<x-public-layout :title="__('privacy.erasure.title')">
    <h1 class="text-xl font-bold">{{ __('privacy.erasure.title') }}</h1>
    <p class="mt-2 text-sm text-gray-600">{{ __('privacy.erasure.intro') }}</p>

    @if (session('public_status') === 'erasure_requested')
        <div class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('privacy.erasure.sent') }}
        </div>
    @endif

    <form method="post" action="{{ route('privacy.erasure.submit') }}" class="mt-6">
        @csrf
        @include('public.partials.honeypot')

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700" for="erasure-email">
                {{ __('privacy.erasure.email_label') }}<span class="text-red-600">*</span>
            </label>
            <input type="email" id="erasure-email" name="email" value="{{ old('email') }}" required
                   class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @include('public.partials.captcha')

        <button type="submit"
                class="mt-4 w-full rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
            {{ __('privacy.erasure.submit') }}
        </button>
    </form>
</x-public-layout>
