@php
    $tone = fn (string $status): string => match ($status) {
        'warning' => 'text-danger-600',
        'unknown' => 'text-gray-400',
        default => 'text-success-600',
    };
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('ops.health.heading')">
        <x-slot name="description">
            @if ($breaches > 0)
                <span class="text-danger-600">{{ __('ops.health.breaches', ['count' => $breaches]) }}</span>
            @else
                {{ __('ops.health.all_ok') }}
            @endif
        </x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ($checks as $check)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('ops.health.metrics.'.$check['key']) }}
                    </div>
                    <div class="mt-1 text-2xl font-semibold {{ $tone($check['status']) }}">
                        {{ $check['display'] }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
