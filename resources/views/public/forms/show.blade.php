{{--
    Hosted / iframe page for a §2.7 form (F-2.7-011 variant 2).

    Deliberately a bare document rather than the site's public layout: this
    page is embedded in a customer's site inside an iframe, so it must carry
    nothing but the form itself. It works with JavaScript disabled — the
    <form> posts to the same endpoint the loader calls with fetch().
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
    <form class="emp-form emp-form-{{ $scope }}" method="post" action="{{ $action }}" accept-charset="UTF-8">
        @csrf

        @if (! empty($formErrors))
            <div class="emp-form__errors" role="alert" style="margin-bottom:12px">
                @foreach ($formErrors as $messages)
                    <div class="emp-form__error">{{ is_array($messages) ? implode(' ', $messages) : $messages }}</div>
                @endforeach
            </div>
        @endif

        {!! $body !!}
    </form>

    {{--
        Multi-step paging (F-2.7-004). Progressive enhancement only: the
        markup above is a complete, submittable form on its own, and this
        script merely pages it. The same function the embed loader runs.
    --}}
    <script>
        @include('public.forms.steps')

        (function () {
            var form = document.querySelector('form.emp-form');
            if (form) {
                empFormSteps(form, {
                    stepUrl: @json($stepUrl),
                    stepOf: @json(__('forms.public.step_of', ['current' => '{current}', 'total' => '{total}']))
                });
            }
        })();
    </script>
</body>
</html>
