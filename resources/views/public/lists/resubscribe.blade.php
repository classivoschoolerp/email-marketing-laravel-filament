{{-- F-1.5-013 — resubscribe page. Reached from the person's own {uid} link,
     so no email input exists: this can never re-add somebody else. --}}
<x-public-layout :title="$title" :custom-css="$customCss" :custom-js="$customJs" :footer-html="$footerHtml">
    <div class="prose max-w-none">
        {!! $content !!}
    </div>

    @if ($blocked)
        {{-- D-74: blacklisted addresses are never resubscribed. --}}
        <div class="mt-6 rounded-md bg-red-50 p-3 text-sm text-red-800">
            {{ __('public.lists.resubscribe_blocked') }}
        </div>
    @else
        @error('email')
            <div class="mt-6 rounded-md bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>
        @enderror

        <form method="post"
              action="{{ route('lists.public.resubscribe.process', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}"
              class="mt-6">
            @csrf
            @include('public.partials.honeypot')

            <p class="mb-4 text-sm text-gray-600">
                {{ __('public.lists.resubscribing_as', ['email' => $subscriber->email]) }}
            </p>

            @include('public.partials.captcha')

            <button type="submit"
                    class="mt-4 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                {{ __('public.lists.resubscribe_button') }}
            </button>
        </form>
    @endif

    <p class="mt-6 text-sm">
        <a class="font-medium text-gray-600 underline"
           href="{{ route('lists.public.preferences', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}">
            {{ __('public.lists.preferences_link') }}
        </a>
    </p>
</x-public-layout>
