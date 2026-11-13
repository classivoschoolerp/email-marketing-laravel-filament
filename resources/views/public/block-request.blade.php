<x-public-layout :title="__('public.block_requests.title')">
    <h1 class="text-xl font-bold">{{ __('public.block_requests.title') }}</h1>
    <p class="mt-2 text-sm text-gray-600">{{ __('public.block_requests.intro') }}</p>

    @if (session('public_status') === 'block_request_sent')
        <div class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('public.block_requests.sent') }}
        </div>
    @endif

    <form method="post" action="{{ route('block_request.submit') }}" class="mt-6">
        @csrf
        @include('public.partials.honeypot')

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700" for="block-email">
                {{ __('lists.embed.email_label') }}<span class="text-red-600">*</span>
            </label>
            <input type="email" id="block-email" name="email" value="{{ old('email') }}" required
                   class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @include('public.partials.captcha')

        <button type="submit"
                class="mt-4 w-full rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
            {{ __('public.block_requests.submit') }}
        </button>
    </form>
</x-public-layout>
