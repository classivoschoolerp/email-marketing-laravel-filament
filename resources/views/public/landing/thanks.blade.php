{{--
    Landing-page thank-you document (spec §2.6 F-2.6-011).

    Always noindex: a thank-you page in a search index is a conversion funnel
    with a hole in it, and it can leak that a campaign exists before launch.
    The customer's own thank-you markup renders when they authored one;
    otherwise the success message does, so this page is never blank.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ \App\Services\I18n\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title }}</title>
    @if ($favicon)
        <link rel="icon" href="{{ $favicon }}">
    @endif
    <style>{!! $baseCss !!}</style>
    @if ($customCss)
        <style>{!! $customCss !!}</style>
    @endif
</head>
<body class="lp-body">
    @if (trim($body) !== '')
        {!! $body !!}
    @else
        <div class="lp-thanks">
            <h1 class="lp-h1">{{ __('landing.public.thanks_heading') }}</h1>
            <p class="lp-lead">{{ $message }}</p>
        </div>
    @endif
</body>
</html>
