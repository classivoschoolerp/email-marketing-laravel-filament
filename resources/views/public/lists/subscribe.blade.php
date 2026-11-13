<x-public-layout :title="$title" :custom-css="$customCss" :custom-js="$customJs" :footer-html="$footerHtml">
    <div class="prose max-w-none">
        {!! $content !!}
    </div>

    @if (session('subscribe_status') === 'success')
        <div class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('public.lists.subscribe_success') }}
        </div>
    @endif

    {{-- enctype: F-2.2.9-001 file-upload fields post through this same form. --}}
    <form method="post" action="{{ route('lists.forms.subscribe', ['list_uid' => $list->uid]) }}" class="mt-6"
          enctype="multipart/form-data">
        @csrf
        {{-- Marks the hosted flow: the POST answers with the pending/confirmed
             pages instead of the embed-form redirects (spec §1.5). --}}
        <input type="hidden" name="hosted" value="1">

        @include('public.partials.honeypot')

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700" for="subscribe-email">
                {{ __('lists.embed.email_label') }}<span class="text-red-600">*</span>
            </label>
            <input type="email" id="subscribe-email" name="email" value="{{ old('email') }}" required
                   class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @foreach ($fields as $field)
            @include('public.partials.field', ['field' => $field, 'values' => $values])
        @endforeach

        @include('public.partials.captcha')

        <button type="submit"
                class="mt-4 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
            {{ __('lists.embed.submit_label') }}
        </button>
    </form>
</x-public-layout>
