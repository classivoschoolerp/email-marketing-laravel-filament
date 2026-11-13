{{--
    Success page for a no-JavaScript form submission (F-2.7-008, "message"
    mode). The loader shows the same string inline; this is the fallback for
    the raw-HTML and iframe variants.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ \App\Services\I18n\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $form->displayName() }}</title>
    <style>
        html,body{margin:0;padding:0;background:transparent}
        body{display:flex;justify-content:center;padding:16px;box-sizing:border-box}
        {!! $css !!}
    </style>
</head>
<body>
    <div class="emp-form emp-form-{{ $scope }}">
        <div class="emp-form__success" style="font-size:15px">{{ $message }}</div>
    </div>
</body>
</html>
