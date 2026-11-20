{{-- Survey thank-you page (spec F-1.9-003 / F-2.8-004). --}}
<x-public-layout :title="$survey->meta_title ?: $title">
    @push('head')
        <meta name="robots" content="noindex">
    @endpush

    <h1 class="text-xl font-bold tracking-tight text-gray-900">{{ $survey->publicName() }}</h1>

    @if ($survey->thank_you_content)
        <div class="prose mt-4 max-w-none text-sm">{!! $survey->thank_you_content !!}</div>
    @else
        <p class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('surveys.public.thanks_default') }}
        </p>
    @endif
</x-public-layout>
