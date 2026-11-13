{{-- Pre-flight checklist (spec §2.3.5 F-2.3.5-008). Error-level failures
     block Schedule/Send; warnings surface without blocking. --}}
<ul class="space-y-1.5">
    @foreach ($checks as $check)
        <li class="flex items-start gap-2 text-sm">
            @if ($check['passed'])
                <x-filament::icon icon="heroicon-m-check-circle" class="mt-0.5 h-5 w-5 text-success-600" />
            @elseif ($check['level'] === 'warning')
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-0.5 h-5 w-5 text-warning-500" />
            @else
                <x-filament::icon icon="heroicon-m-x-circle" class="mt-0.5 h-5 w-5 text-danger-600" />
            @endif
            <span class="{{ $check['passed'] ? 'text-gray-700 dark:text-gray-200' : 'font-medium text-gray-900 dark:text-white' }}">
                {{ $check['label'] }}
                @if ($check['detail'] !== null)
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</span>
                @endif
            </span>
        </li>
    @endforeach
</ul>
