<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.customer.quick_actions.heading')">
        <div class="flex flex-wrap gap-3">
            @foreach ($this->actions() as $action)
                <x-filament::button tag="a" :href="$action['url']" :icon="$action['icon']" size="sm">
                    {{ $action['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
