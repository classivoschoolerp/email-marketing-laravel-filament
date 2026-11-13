<x-install-layout :step="$step" :title="__('install.services.title')">
    <h2 class="text-lg font-semibold">{{ __('install.services.title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('install.services.intro') }}</p>

    <div class="mt-6 space-y-5 text-sm">
        <div>
            <h3 class="font-medium">{{ __('install.services.cron') }}</h3>
            <p class="text-xs text-gray-500">{{ __('install.services.cron_note') }}</p>
            <pre class="mt-1 overflow-x-auto rounded-md bg-gray-100 p-3 font-mono text-xs">{{ $instructions['cron'] }}</pre>
        </div>

        <div>
            <h3 class="font-medium">{{ __('install.services.worker') }}</h3>
            <p class="text-xs text-gray-500">{{ __('install.services.worker_note') }}</p>
            <pre class="mt-1 overflow-x-auto rounded-md bg-gray-100 p-3 font-mono text-xs">{{ $instructions['worker'] }}</pre>
        </div>

        <div>
            <h3 class="font-medium">{{ __('install.services.heartbeat') }}</h3>
            <p class="text-xs text-gray-500">{{ __('install.services.heartbeat_note') }}</p>
            <pre class="mt-1 overflow-x-auto rounded-md bg-gray-100 p-3 font-mono text-xs">{{ $instructions['heartbeat'] }}</pre>
        </div>

        <div>
            <h3 class="font-medium">{{ __('install.services.supervisor') }}</h3>
            <pre class="mt-1 overflow-x-auto rounded-md bg-gray-100 p-3 font-mono text-xs">{{ $instructions['supervisor'] }}</pre>
        </div>
    </div>

    <form method="POST" action="{{ route('install.complete') }}" class="mt-8">
        @csrf
        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700">
            {{ __('install.services.finish') }}
        </button>
        <p class="mt-2 text-xs text-gray-500">{{ __('install.services.finish_note') }}</p>
    </form>
</x-install-layout>
