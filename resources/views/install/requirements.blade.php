<x-install-layout :step="$step" :title="__('install.requirements.title')">
    <h2 class="text-lg font-semibold">{{ __('install.requirements.title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('install.requirements.intro') }}</p>

    <dl class="mt-6 space-y-4 text-sm">
        <div>
            <dt class="font-medium">{{ __('install.requirements.php') }}</dt>
            <dd class="{{ $requirements['php']['ok'] ? 'text-emerald-700' : 'text-red-600' }}">
                {{ $requirements['php']['current'] }}
                ({{ __('install.requirements.required', ['value' => $requirements['php']['required']]) }})
            </dd>
        </div>

        <div>
            <dt class="font-medium">{{ __('install.requirements.memory') }}</dt>
            <dd class="{{ $requirements['memory']['ok'] ? 'text-emerald-700' : 'text-red-600' }}">
                {{ $requirements['memory']['current'] }}
                ({{ __('install.requirements.required', ['value' => $requirements['memory']['required_mb'].'M']) }})
            </dd>
        </div>

        <div>
            <dt class="font-medium">{{ __('install.requirements.extensions') }}</dt>
            <dd class="mt-1 flex flex-wrap gap-1">
                @foreach ($requirements['extensions'] as $extension)
                    <span class="rounded px-2 py-0.5 text-xs {{ $extension['ok'] ? 'bg-emerald-100 text-emerald-700' : ($extension['required'] ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                        {{ $extension['name'] }}{{ $extension['required'] ? '' : '*' }}
                    </span>
                @endforeach
            </dd>
            <dd class="mt-1 text-xs text-gray-500">{{ __('install.requirements.optional_note') }}</dd>
        </div>

        <div>
            <dt class="font-medium">{{ __('install.requirements.paths') }}</dt>
            <dd class="mt-1 space-y-1">
                @foreach ($requirements['paths'] as $path)
                    <div class="{{ $path['ok'] ? 'text-emerald-700' : 'text-red-600' }}">
                        <span class="font-mono">{{ $path['path'] }}</span>
                        @unless ($path['allowed'])
                            <span class="text-xs">{{ __('install.requirements.open_basedir_denied') }}</span>
                        @endunless
                    </div>
                @endforeach
            </dd>
        </div>

        @if ($requirements['open_basedir']['enabled'])
            <div>
                <dt class="font-medium">{{ __('install.requirements.open_basedir') }}</dt>
                <dd class="font-mono text-xs">{{ implode(' : ', $requirements['open_basedir']['paths']) }}</dd>
                <dd class="mt-1 text-xs text-gray-500">{{ __('install.requirements.open_basedir_note') }}</dd>
            </div>
        @endif
    </dl>

    <div class="mt-8">
        @if ($requirements['passed'])
            <a href="{{ route('install.database') }}"
               class="inline-block rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700">
                {{ __('install.continue') }}
            </a>
        @else
            <p class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ __('install.requirements.failed') }}</p>
        @endif
    </div>
</x-install-layout>
