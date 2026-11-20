{{-- Survey start page (spec F-2.8-004 / §1.9). --}}
<x-public-layout :title="$survey->meta_title ?: $title">
    @push('head')
        <meta name="robots" content="noindex">
        @if ($survey->meta_description)
            <meta name="description" content="{{ $survey->meta_description }}">
        @endif
    @endpush

    <h1 class="text-2xl font-bold tracking-tight text-gray-900">{{ $survey->publicName() }}</h1>

    @if ($survey->description)
        <p class="mt-2 text-sm text-gray-600">{{ $survey->description }}</p>
    @endif

    @if ($survey->start_page_content)
        <div class="prose mt-4 max-w-none text-sm">{!! $survey->start_page_content !!}</div>
    @endif

    @if ($subscriber !== null)
        {{-- F-2.8-009: the visitor is identified, say so before they answer. --}}
        <p class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('surveys.public.identified_as', ['email' => $subscriber->email]) }}
        </p>
    @elseif ($survey->allow_anonymous)
        <p class="mt-4 text-xs text-gray-500">{{ __('surveys.public.anonymous_notice') }}</p>
    @endif

    <form method="post" action="{{ route('surveys.public.begin', ['uid' => $survey->uid]) }}" class="mt-6">
        @csrf
        @if ($subscriber !== null)
            <input type="hidden" name="s" value="{{ $subscriber->uid }}">
        @endif

        @include('public.partials.captcha')

        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
            {{ __('surveys.public.start_button') }}
        </button>
    </form>
</x-public-layout>
