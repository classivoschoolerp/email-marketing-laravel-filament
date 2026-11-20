{{--
    One wizard page of a survey (spec F-1.9-001): the questions of this step
    plus a progress bar. Inputs come from the SHARED public field partial, so
    a survey question of any of the 18 types renders exactly like the same
    type on a list page — one renderer, one behaviour.
--}}
<x-public-layout :title="$survey->meta_title ?: $title">
    @push('head')
        <meta name="robots" content="noindex">
    @endpush

    <h1 class="text-xl font-bold tracking-tight text-gray-900">{{ $survey->publicName() }}</h1>

    @if ($survey->show_progress_bar && $stepCount > 1)
        @php($percent = (int) round(($step / $stepCount) * 100))
        <div class="mt-4" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
            <div class="flex justify-between text-xs text-gray-500">
                <span>{{ __('surveys.public.step_of', ['step' => $step, 'total' => $stepCount]) }}</span>
                <span>{{ $percent }}%</span>
            </div>
            <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200">
                <div class="h-2 rounded-full bg-emerald-600" style="width: {{ $percent }}%"></div>
            </div>
        </div>
    @endif

    <form method="post"
          action="{{ route('surveys.public.step.save', ['uid' => $survey->uid, 'responder' => $responder->uid, 'step' => $step]) }}"
          class="mt-6">
        @csrf
        @include('public.partials.honeypot')

        @foreach ($fields as $field)
            @include('public.partials.field', ['field' => $field, 'values' => $values])
        @endforeach

        <button type="submit"
                class="mt-4 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
            {{ $step < $stepCount ? __('surveys.public.next') : __('surveys.public.finish') }}
        </button>
    </form>
</x-public-layout>
