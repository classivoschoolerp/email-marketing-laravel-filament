{{-- §2.1 F-2.1-007 getting-started checklist (WP-40). --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('analytics.onboarding.heading')">
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('analytics.onboarding.subheading', ['completed' => $completed, 'total' => $total]) }}
        </p>

        <div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
            <div class="h-2 rounded-full bg-primary-500" style="width: {{ $percent }}%"
                 data-testid="onboarding-progress"></div>
        </div>

        <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($steps as $step)
                <li class="flex items-start gap-2 rounded-lg bg-gray-50 p-3 dark:bg-gray-800"
                    data-testid="onboarding-{{ $step['key'] }}"
                    data-done="{{ $step['done'] ? '1' : '0' }}">
                    @if ($step['done'])
                        <x-filament::icon icon="heroicon-m-check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-success-500" />
                    @else
                        <x-filament::icon icon="heroicon-o-clock" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                    @endif

                    <div class="min-w-0">
                        <div class="text-sm font-medium {{ $step['done'] ? 'text-gray-500 line-through dark:text-gray-400' : '' }}">
                            {{ __('analytics.onboarding.steps.'.$step['key'].'.title') }}
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('analytics.onboarding.steps.'.$step['key'].'.hint') }}
                        </p>

                        @unless ($step['done'])
                            <a href="{{ $urls[$step['key']] }}"
                               class="mt-1 inline-block text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ __('analytics.onboarding.steps.'.$step['key'].'.action') }}
                            </a>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
