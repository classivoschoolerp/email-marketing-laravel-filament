<x-guest-layout :title="__('auth-module.reset.reset_title')">
    <h1 class="text-xl font-semibold">{{ __('auth-module.reset.reset_title') }}</h1>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="block text-sm font-medium">{{ __('auth-module.reset.email') }}</label>
            <input id="email" name="email" type="email" required value="{{ old('email', $email) }}"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">{{ __('auth-module.reset.password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror

            {{-- Password strength meter (F-1.4-002) --}}
            <div id="strength-meter" class="mt-2" aria-live="polite">
                <div class="h-1.5 w-full overflow-hidden rounded bg-gray-200">
                    <div id="strength-bar" class="h-full w-0 rounded bg-red-500 transition-all"></div>
                </div>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('auth-module.reset.strength.label') }}:
                    <span id="strength-text" class="font-medium"></span>
                </p>
            </div>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">{{ __('auth-module.reset.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>

        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
            {{ __('auth-module.reset.submit') }}
        </button>
    </form>

    @push('scripts')
        <script>
            (function () {
                var labels = @js([
                    __('auth-module.reset.strength.weak'),
                    __('auth-module.reset.strength.fair'),
                    __('auth-module.reset.strength.good'),
                    __('auth-module.reset.strength.strong'),
                ]);
                var colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-emerald-500'];
                var input = document.getElementById('password');
                var bar = document.getElementById('strength-bar');
                var text = document.getElementById('strength-text');

                function score(value) {
                    var s = 0;
                    if (value.length >= 8) s++;
                    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) s++;
                    if (/\d/.test(value)) s++;
                    if (value.length >= 12 || /[^A-Za-z0-9]/.test(value)) s++;
                    return Math.max(0, Math.min(3, s - 1));
                }

                input.addEventListener('input', function () {
                    var value = input.value;
                    if (value === '') {
                        bar.style.width = '0%';
                        text.textContent = '';
                        return;
                    }
                    var s = score(value);
                    bar.className = 'h-full rounded transition-all ' + colors[s];
                    bar.style.width = ((s + 1) * 25) + '%';
                    text.textContent = labels[s];
                });
            })();
        </script>
    @endpush
</x-guest-layout>
