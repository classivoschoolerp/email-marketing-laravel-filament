<x-mail::message>
# {{ __('imports.export.mail.heading') }}

{{ __('imports.export.mail.body', ['name' => $exportName]) }}

<x-mail::button :url="$downloadUrl">
{{ __('imports.export.mail.button') }}
</x-mail::button>

@if ($expiresAtFormatted !== null)
{{ __('imports.export.mail.expires', ['date' => $expiresAtFormatted]) }}
@endif

{{ config('app.name') }}
</x-mail::message>
