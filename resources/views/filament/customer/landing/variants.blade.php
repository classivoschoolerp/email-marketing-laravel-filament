<x-filament-panels::page>
    {{-- A/B variants with traffic split, comparison and winner selection
         (spec §2.6 F-2.6-004). Every number here comes from
         LandingPageStats — including the refusal to name a winner when the
         sample is too small. --}}

    <div class="space-y-3">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('landing.variants.intro') }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('landing.variants.method', ['min' => $minSample]) }}
        </p>

        @if ($suggested)
            <div class="rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-500/10 dark:text-success-400">
                {{ __('landing.variants.suggested_winner', ['name' => $suggested->name]) }}
            </div>
        @else
            <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {{ __('landing.variants.no_suggested_winner') }}
            </div>
        @endif
    </div>

    @if (count($rows) === 0)
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">{{ __('landing.variants.empty') }}</p>
    @else
        <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left dark:bg-gray-800">
                    <tr>
                        <th class="p-3">{{ __('landing.variants.name') }}</th>
                        <th class="p-3">{{ __('landing.variants.share') }}</th>
                        <th class="p-3">{{ __('landing.analytics.visits') }}</th>
                        <th class="p-3">{{ __('landing.analytics.conversions') }}</th>
                        <th class="p-3">{{ __('landing.analytics.conversion_rate') }}</th>
                        <th class="p-3">{{ __('landing.variants.uplift') }}</th>
                        <th class="p-3">{{ __('landing.variants.confidence') }}</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($variant = $row['variant'])
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="p-3">
                                <a class="font-medium text-primary-600 hover:underline"
                                   href="{{ \App\Filament\Customer\Resources\LandingPageResource::getUrl('build', ['record' => $page]) }}?variant={{ $variant->uid }}">
                                    {{ $variant->name }}
                                </a>
                                @if ($row['is_control'])
                                    <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-800">{{ __('landing.variants.control') }}</span>
                                @endif
                                @if ($variant->is_winner)
                                    <span class="ml-2 rounded bg-success-100 px-1.5 py-0.5 text-xs text-success-700 dark:bg-success-500/10">{{ __('landing.variants.winner') }}</span>
                                @endif
                                @unless ($variant->enabled)
                                    <span class="ml-2 rounded bg-warning-100 px-1.5 py-0.5 text-xs text-warning-700 dark:bg-warning-500/10">{{ __('landing.status.unpublished') }}</span>
                                @endunless
                            </td>
                            <td class="p-3">
                                @if (! $variant->enabled || $totalWeight <= 0)
                                    —
                                @else
                                    {{ number_format(($variant->weight / $totalWeight) * 100, 1) }}%
                                @endif
                            </td>
                            <td class="p-3">{{ number_format($row['visits']) }}</td>
                            <td class="p-3">{{ number_format($row['conversions']) }}</td>
                            <td class="p-3">{{ number_format($row['conversion_rate'], 2) }}%</td>
                            <td class="p-3">
                                {{ $row['uplift'] === null ? '—' : number_format($row['uplift'], 2).'%' }}
                            </td>
                            <td class="p-3">
                                @if ($row['confidence'] === null)
                                    <span class="text-gray-400">{{ __('landing.variants.not_enough_data') }}</span>
                                @else
                                    {{ number_format($row['confidence'] * 100, 1) }}%
                                    <span @class([
                                        'ml-1 rounded px-1.5 py-0.5 text-xs',
                                        'bg-success-100 text-success-700 dark:bg-success-500/10' => $row['significant'],
                                        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => ! $row['significant'],
                                    ])>
                                        {{ $row['significant'] ? __('landing.variants.significant') : __('landing.variants.not_significant') }}
                                    </span>
                                @endif
                            </td>
                            <td class="p-3">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($variant->is_winner)
                                        <x-filament::button size="xs" color="gray" wire:click="clearWinner">
                                            {{ __('landing.actions.clear_winner') }}
                                        </x-filament::button>
                                    @else
                                        <x-filament::button size="xs" wire:click="declareWinner({{ $variant->id }})">
                                            {{ __('landing.actions.declare_winner') }}
                                        </x-filament::button>
                                    @endif

                                    <x-filament::button size="xs" color="gray" wire:click="toggleVariant({{ $variant->id }})">
                                        {{ $variant->enabled ? __('landing.actions.unpublish') : __('landing.actions.publish') }}
                                    </x-filament::button>

                                    <x-filament::button size="xs" color="danger" wire:click="deleteVariant({{ $variant->id }})">
                                        {{ __('landing.actions.delete_variant') }}
                                    </x-filament::button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('landing.variants.winner_note') }}</p>
    @endif
</x-filament-panels::page>
