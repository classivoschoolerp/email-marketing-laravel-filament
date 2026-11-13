<x-mail::message>
{{ __('public.block_requests.mail.intro') }}

<x-mail::button :url="$confirmUrl">
{{ __('public.block_requests.mail.button') }}
</x-mail::button>

{{ __('public.block_requests.mail.ignore') }}
</x-mail::message>
