<x-public-layout :title="__('public.block_requests.title')">
    <h1 class="text-xl font-bold">{{ __('public.block_requests.confirmed_title') }}</h1>
    <p class="mt-2 text-sm text-gray-600">
        {{ __('public.block_requests.confirmed_body', ['email' => $blockRequest->email]) }}
    </p>
</x-public-layout>
