{{--
    Public landing page (spec §1.8 F-1.8-001/005).

    Deliberately a STANDALONE document rather than <x-public-layout>: the page
    can answer on the customer's own domain, where the platform's chrome, site
    name and Vite bundle would all be the wrong thing to serve. Everything the
    page needs is prepared by App\Services\Landing\LandingPageRenderer, which
    is also the single place where anything unescaped is made safe:
      · $doc['body']         — the customer's own builder output (page content)
      · $doc['customCss/Js'] — sanitised per F-1.5-017 (closing tag stripped)
      · $doc['headScripts']  — vendor loaders with PATTERN-VALIDATED ids only
      · $doc['countdownScript'] — a literal, emitted only if a countdown exists
--}}
<!DOCTYPE html>
<html lang="{{ $doc['lang'] }}" dir="{{ \App\Services\I18n\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc['title'] }}</title>

    {{-- SEO (F-2.6-007) --}}
    <meta name="robots" content="{{ $doc['robots'] }}">
    @if ($doc['description'])
        <meta name="description" content="{{ $doc['description'] }}">
    @endif
    <link rel="canonical" href="{{ $doc['canonical'] }}">
    @if ($doc['favicon'])
        <link rel="icon" href="{{ $doc['favicon'] }}">
    @endif

    {{-- Open Graph / Twitter share preview (F-1.8-005) --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $doc['title'] }}">
    @if ($doc['description'])
        <meta property="og:description" content="{{ $doc['description'] }}">
    @endif
    @if ($doc['ogImage'])
        <meta property="og:image" content="{{ $doc['ogImage'] }}">
    @endif
    <meta property="og:url" content="{{ $doc['canonical'] }}">
    <meta name="twitter:card" content="{{ $doc['ogImage'] ? 'summary_large_image' : 'summary' }}">

    <style>{!! $doc['baseCss'] !!}</style>
    @if ($doc['customCss'])
        <style>{!! $doc['customCss'] !!}</style>
    @endif

    @if ($doc['headScripts'] !== '')
        {!! $doc['headScripts'] !!}
    @endif
</head>
<body class="lp-body">
    @if ($doc['bodyScripts'] !== '')
        {!! $doc['bodyScripts'] !!}
    @endif

    {!! $doc['body'] !!}

    {{-- WP-22 cookie banner: the same consent surface every public page shows. --}}
    @include('public.partials.cookie-consent')

    @if ($doc['countdownScript'] !== '')
        <script>{!! $doc['countdownScript'] !!}</script>
    @endif

    @if ($doc['customJs'])
        <script>{!! $doc['customJs'] !!}</script>
    @endif
</body>
</html>
