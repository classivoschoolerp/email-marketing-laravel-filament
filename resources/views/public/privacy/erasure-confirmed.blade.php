{{-- WP-22 (spec §3.25.16 F-3.25.16-003) --}}
<x-public-layout :title="__('privacy.erasure.confirmed_title')">
    <h1 class="text-xl font-bold">{{ __('privacy.erasure.confirmed_title') }}</h1>
    <p class="mt-3 text-sm text-gray-700">
        {{ __('privacy.erasure.confirmed_body', ['email' => $erasureRequest->email]) }}
    </p>
</x-public-layout>
