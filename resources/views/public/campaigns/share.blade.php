<x-public-layout :title="$meta['title']">
    @push('head')
        {{-- OG tags for FB/X/LinkedIn/WhatsApp share previews (F-1.6-003). --}}
        <meta property="og:type" content="article">
        <meta property="og:title" content="{{ $meta['title'] }}">
        @if (!empty($meta['description']))
            <meta property="og:description" content="{{ $meta['description'] }}">
            <meta name="description" content="{{ $meta['description'] }}">
        @endif
        @if (!empty($meta['image']))
            <meta property="og:image" content="{{ $meta['image'] }}">
        @endif
        <meta property="og:url" content="{{ url()->current() }}">
        <meta name="twitter:card" content="summary_large_image">
    @endpush

    <h1 class="text-xl font-bold">{{ $meta['title'] }}</h1>
    @if (!empty($meta['description']))
        <p class="mt-2 text-sm text-gray-600">{{ $meta['description'] }}</p>
    @endif

    <a href="{{ $webViewUrl }}"
       class="mt-6 inline-block rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
        {{ __('public.campaigns.open_web_view') }}
    </a>
</x-public-layout>
