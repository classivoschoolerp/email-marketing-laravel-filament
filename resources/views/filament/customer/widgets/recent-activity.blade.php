<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.customer.activity.heading')">
        @php($events = $this->events())

        @if ($events === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('dashboards.customer.activity.empty') }}
            </p>
        @else
            <ul class="space-y-2">
                @foreach ($events as $event)
                    <li class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">
                            {{ __('dashboards.customer.activity.types.'.$event['type'], ['subject' => $event['subject']]) }}
                        </span>
                        <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                            {{ $event['at']?->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
