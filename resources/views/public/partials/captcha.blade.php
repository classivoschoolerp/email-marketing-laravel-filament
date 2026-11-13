@php($captcha = \App\Services\Captcha\CaptchaManager::driver())
@if ($captcha->enabled() && $captcha->siteKey() !== null)
    {{-- Configured captcha widget (F-1.5-016), same markup as the embed form. --}}
    @if ($captcha->scriptUrl() !== null)
        @push('scripts')
            <script src="{{ $captcha->scriptUrl() }}" async defer></script>
        @endpush
    @endif
    <div class="g-recaptcha h-captcha" data-sitekey="{{ $captcha->siteKey() }}"></div>
    @error('captcha')
        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
@endif
