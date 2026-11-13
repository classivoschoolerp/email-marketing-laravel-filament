{{-- F-1.5-012 — preference center: choose topics/frequency instead of a full
     unsubscribe. Page copy comes from the editable preference-center list page
     (3-layer merge), so custom CSS/JS (F-1.5-017) applies here too. --}}
<x-public-layout :title="$title" :custom-css="$customCss" :custom-js="$customJs" :footer-html="$footerHtml">
    <div class="prose max-w-none">
        {!! $content !!}
    </div>

    @if (session('public_status') === 'preferences_saved')
        <div class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('public.lists.preferences_saved') }}
        </div>
    @endif

    <form method="post"
          action="{{ route('lists.public.preferences.save', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}"
          class="mt-6">
        @csrf
        @include('public.partials.honeypot')

        <p class="mb-4 text-sm text-gray-600">
            {{ __('public.lists.preferences_as', ['email' => $subscriber->email]) }}
        </p>

        <fieldset class="mb-6">
            <legend class="text-sm font-medium text-gray-700">{{ __('public.lists.topics_label') }}</legend>
            <p class="mt-1 text-xs text-gray-500">{{ __('public.lists.topics_help') }}</p>

            @foreach ($topics as $topic)
                <label class="mt-3 flex items-start gap-2 text-sm">
                    <input type="checkbox" name="topics[]" value="{{ $topic['uid'] }}"
                           class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                           @checked($topic['subscribed'])>
                    <span>
                        <span class="font-medium text-gray-900">{{ $topic['name'] }}</span>
                        @if ($topic['description'])
                            <span class="block text-xs text-gray-500">{{ $topic['description'] }}</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </fieldset>

        <fieldset class="mb-6">
            <legend class="text-sm font-medium text-gray-700">{{ __('public.lists.frequency_label') }}</legend>

            @foreach ($frequencies as $value => $label)
                <label class="mt-2 flex items-center gap-2 text-sm">
                    <input type="radio" name="frequency" value="{{ $value }}"
                           class="border-gray-300 text-emerald-600 focus:ring-emerald-500"
                           @checked(old('frequency', $frequency) === $value)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach

            @error('frequency')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror

            <div class="mt-3">
                <label class="block text-sm font-medium text-gray-700" for="pause-days">
                    {{ __('public.lists.pause_days_label') }}
                </label>
                <input type="number" id="pause-days" name="pause_days" min="1" max="365"
                       value="{{ old('pause_days', $pauseDays) }}"
                       class="mt-1 block w-40 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('pause_days')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </fieldset>

        @include('public.partials.captcha')

        <button type="submit"
                class="mt-4 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
            {{ __('public.lists.save_preferences') }}
        </button>
    </form>

    <p class="mt-6 text-sm">
        <a class="font-medium text-gray-600 underline"
           href="{{ route('lists.public.unsubscribe', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}">
            {{ __('public.lists.unsubscribe_all_link') }}
        </a>
    </p>
</x-public-layout>
