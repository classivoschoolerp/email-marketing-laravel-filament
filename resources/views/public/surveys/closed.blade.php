{{--
    Survey unavailable (spec F-1.9-004). The reason is named so a respondent
    understands whether they are early, late, capped or already done.
--}}
<x-public-layout :title="$survey->meta_title ?: $title">
    @push('head')
        <meta name="robots" content="noindex">
    @endpush

    <h1 class="text-xl font-bold tracking-tight text-gray-900">{{ $survey->publicName() }}</h1>

    <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
        {{ $survey->closed_message ?: __('surveys.public.closed.'.$reason) }}
    </p>
</x-public-layout>
