{{--
    Landing-page password gate (spec §1.8 F-1.8-006).

    Always noindex: a gated page must not be summarised in a search result,
    and the gate itself is not content. The document deliberately contains
    NOTHING from the page body — that is the whole point of the gate.
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
    <div class="lp-thanks">
        <h1 class="lp-h1">{{ __('landing.public.password_heading') }}</h1>
        <p class="lp-lead">{{ __('landing.public.password_lead') }}</p>

        @if ($error)
            <p class="lp-error" role="alert">{{ $error }}</p>
        @endif

        <form class="lp-form lp-gate" method="POST"
              action="{{ route('landing.public.unlock', ['slug' => $page->slug]) }}">
            @csrf
            <label for="lp-password">{{ __('landing.public.password_label') }}</label>
            <input id="lp-password" type="password" name="password" autocomplete="current-password"
                   required autofocus>
            <button type="submit">{{ __('landing.public.password_submit') }}</button>
        </form>
    </div>
</body>
</html>
