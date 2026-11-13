<x-mail::message>
{{ __('privacy.erasure.mail.line_1') }}

{{ __('privacy.erasure.mail.line_2', ['hours' => $expiresInHours]) }}

<x-mail::button :url="$confirmUrl">
{{ __('privacy.erasure.mail.action') }}
</x-mail::button>

{{ __('privacy.erasure.mail.line_3') }}
</x-mail::message>
