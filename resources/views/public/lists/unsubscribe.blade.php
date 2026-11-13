<x-public-layout :title="$title" :custom-css="$customCss" :custom-js="$customJs" :footer-html="$footerHtml">
    <div class="prose max-w-none">
        {!! $content !!}
    </div>

    <form method="post"
          action="{{ route('lists.public.unsubscribe.confirm', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}"
          class="mt-6">
        @csrf
        @include('public.partials.honeypot')

        <p class="mb-4 text-sm text-gray-600">
            {{ __('public.lists.unsubscribing_as', ['email' => $subscriber->email]) }}
        </p>

        {{-- F-1.5-012: the way out that is not a full unsubscribe. --}}
        <p class="mb-4 text-sm">
            <a class="font-medium text-emerald-700 underline"
               href="{{ route('lists.public.preferences', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}">
                {{ __('public.lists.preferences_link') }}
            </a>
        </p>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700" for="unsubscribe-reason">
                {{ __('public.lists.reason_label') }}
            </label>
            <select id="unsubscribe-reason" name="reason"
                    class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('lists.embed.select_placeholder') }}</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason }}" @selected(old('reason') === $reason)>
                        {{ __('public.lists.reasons.'.$reason) }}
                    </option>
                @endforeach
            </select>
            @error('reason')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700" for="unsubscribe-reason-text">
                {{ __('public.lists.reason_text_label') }}
            </label>
            <textarea id="unsubscribe-reason-text" name="reason_text" rows="3"
                      class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('reason_text') }}</textarea>
            @error('reason_text')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @include('public.partials.captcha')

        <button type="submit"
                class="mt-4 w-full rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
            {{ __('public.lists.unsubscribe_button') }}
        </button>
    </form>
</x-public-layout>
