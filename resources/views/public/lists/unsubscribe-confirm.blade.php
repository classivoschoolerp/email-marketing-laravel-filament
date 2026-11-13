<x-public-layout :title="$title" :custom-css="$customCss" :custom-js="$customJs" :footer-html="$footerHtml">
    <div class="prose max-w-none">
        {!! $content !!}
    </div>

    <form method="post"
          action="{{ route('lists.public.unsubscribe.process', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}"
          class="mt-6">
        @csrf
        @include('public.partials.honeypot')

        <input type="hidden" name="reason" value="{{ $reason }}">
        <input type="hidden" name="reason_text" value="{{ $reasonText }}">

        <button type="submit"
                class="w-full rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
            {{ __('public.lists.unsubscribe_confirm_button') }}
        </button>
    </form>
</x-public-layout>
