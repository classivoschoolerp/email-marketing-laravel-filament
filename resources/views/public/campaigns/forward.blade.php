{{-- §1.6 F-1.6-002 — forward to a friend. Honeypot + captcha + throttle. --}}
<x-public-layout :title="__('campaigns.forward.title')">
    <h1 class="text-xl font-bold">{{ __('campaigns.forward.title') }}</h1>
    <p class="mt-2 text-sm text-gray-600">
        {{ __('campaigns.forward.intro', ['subject' => $campaign->subject ?: $campaign->name]) }}
    </p>

    @if (session('public_status') === 'campaign_forwarded')
        <div class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
            {{ __('campaigns.forward.sent') }}
        </div>
    @endif

    <form method="post"
          action="{{ route('campaigns.track.forward.submit', ['campaign_uid' => $campaign->uid, 'subscriber_uid' => $subscriber->uid]) }}"
          class="mt-6">
        @csrf
        @include('public.partials.honeypot')

        @for ($i = 0; $i < $maxRecipients; $i++)
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700" for="forward-recipient-{{ $i }}">
                    {{ __('campaigns.forward.recipient', ['n' => $i + 1]) }}
                </label>
                <input type="email"
                       id="forward-recipient-{{ $i }}"
                       name="recipients[]"
                       value="{{ old('recipients.'.$i) }}"
                       @if ($i === 0) required @endif
                       class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('recipients.'.$i)
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endfor

        @error('recipients')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700" for="forward-note">
                {{ __('campaigns.forward.note') }}
            </label>
            <textarea id="forward-note" name="note" rows="3" maxlength="500"
                      class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('note') }}</textarea>
            @error('note')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @include('public.partials.captcha')

        <button type="submit"
                class="mt-4 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
            {{ __('campaigns.forward.submit') }}
        </button>
    </form>

    <p class="mt-4 text-xs text-gray-500">{{ __('campaigns.forward.privacy_note') }}</p>
</x-public-layout>
