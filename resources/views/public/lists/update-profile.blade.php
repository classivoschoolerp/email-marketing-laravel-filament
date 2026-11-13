<x-public-layout :title="$title" :custom-css="$customCss" :custom-js="$customJs" :footer-html="$footerHtml">
    <div class="prose max-w-none">
        {!! $content !!}
    </div>

    @if (session('public_status') === 'profile_updated')
        <div class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('public.lists.profile_updated') }}
        </div>
    @endif

    <form method="post"
          action="{{ route('lists.public.update_profile.save', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]) }}"
          class="mt-6"
          {{-- enctype: F-2.2.9-001 file-upload fields can be edited here. --}}
          enctype="multipart/form-data">
        @csrf
        @include('public.partials.honeypot')

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700" for="profile-email">
                {{ __('lists.embed.email_label') }}<span class="text-red-600">*</span>
            </label>
            <input type="email" id="profile-email" name="email" value="{{ old('email', $subscriber->email) }}" required
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
            {{ __('public.lists.save_profile') }}
        </button>
    </form>

    {{-- WP-22 (spec §4.5 F-4.5-001): GDPR self-service — portability export
         (short-lived signed link, rate-limited) and the right-to-erasure
         request flow. Each link only appears while its setting is on. --}}
    @php
        try {
            $empPrivacy = \App\Services\Settings\SettingsReader::privacy();
            $empDataExport = $empPrivacy->dataExportEnabled();
            $empErasure = $empPrivacy->erasureWorkflowEnabled();
        } catch (\Throwable) {
            $empDataExport = false;
            $empErasure = false;
        }
    @endphp

    @if ($empDataExport || $empErasure)
        <div class="mt-8 border-t border-gray-200 pt-4 text-sm">
            @if ($empDataExport)
                <p>
                    <a class="font-medium text-emerald-700 underline"
                       href="{{ \App\Http\Controllers\Public\PrivacyController::exportUrl($subscriber, $list->uid) }}">
                        {{ __('privacy.export.link') }}
                    </a>
                </p>
                <p class="mt-1 text-xs text-gray-500">{{ __('privacy.export.description') }}</p>
            @endif

            @if ($empErasure)
                <p class="mt-3">
                    <a class="font-medium text-red-700 underline" href="{{ route('privacy.erasure.form') }}">
                        {{ __('privacy.erasure.link') }}
                    </a>
                </p>
            @endif
        </div>
    @endif
</x-public-layout>
