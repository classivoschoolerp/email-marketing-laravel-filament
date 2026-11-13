{{-- Site root of a single-company install: a small page that points to the two sign-ins. --}}
<x-guest-layout :title="config('app.name')">
    <p class="mb-6 text-center text-sm text-gray-600">{{ __('public.home.intro') }}</p>

    <div class="flex flex-col gap-3">
        <a href="{{ url('/customer/login') }}"
           class="rounded-md bg-emerald-600 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-emerald-700">
            {{ __('public.home.workspace_login') }}
        </a>
        <a href="{{ url('/admin/login') }}"
           class="rounded-md border border-gray-300 px-4 py-2 text-center text-sm font-semibold text-gray-700 hover:bg-gray-50">
            {{ __('public.home.admin_login') }}
        </a>
    </div>
</x-guest-layout>
