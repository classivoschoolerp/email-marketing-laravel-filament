{{-- §2.7 form analytics (F-2.7-012). --}}
@php
    $summary = $this->summary();
    $daily = $this->daily();
    $peak = max(1, collect($daily)->max('views') ?: 1);
@endphp

<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            ['label' => __('forms.analytics.views'), 'value' => number_format($summary['views'])],
            ['label' => __('forms.analytics.submissions'), 'value' => number_format($summary['submissions'])],
            ['label' => __('forms.analytics.conversion_rate'), 'value' => number_format($summary['conversion_rate'], 1).'%'],
        ] as $tile)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                <div class="mt-1 text-3xl font-semibold tracking-tight">{{ $tile['value'] }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ __('forms.analytics.daily_title') }}</x-slot>

        {{-- A CSS bar series: no chart dependency for a 30-bucket sparkline. --}}
        <div class="flex h-32 items-end gap-1" role="img" aria-label="{{ __('forms.analytics.daily_title') }}">
            @foreach ($daily as $day)
                <div class="flex-1"
                     title="{{ $day['date'] }} — {{ $day['views'] }} / {{ $day['submissions'] }}">
                    <div class="w-full rounded-t bg-primary-500/30"
                         style="height: {{ (int) round(($day['views'] / $peak) * 100) }}px"></div>
                    <div class="w-full rounded-b bg-primary-600"
                         style="height: {{ (int) round(($day['submissions'] / $peak) * 100) }}px"></div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-500">{{ __('forms.analytics.daily_legend') }}</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('forms.analytics.by_source_title') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="py-2">{{ __('forms.analytics.source') }}</th>
                    <th class="py-2 text-right">{{ __('forms.analytics.views') }}</th>
                    <th class="py-2 text-right">{{ __('forms.analytics.submissions') }}</th>
                    <th class="py-2 text-right">{{ __('forms.analytics.conversion_rate') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->bySource() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-2 font-medium">{{ $row['source'] }}</td>
                        <td class="py-2 text-right">{{ number_format($row['views']) }}</td>
                        <td class="py-2 text-right">{{ number_format($row['submissions']) }}</td>
                        <td class="py-2 text-right">{{ number_format($row['conversion_rate'], 1) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-4 text-center text-gray-500">{{ __('forms.analytics.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- F-2.7-013: per-arm conversion + significance. --}}
    @php $ab = $this->abReport(); @endphp

    @if (count($ab['arms']) > 0)
        <x-filament::section>
            <x-slot name="heading">{{ __('forms.analytics.ab_title') }}</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-2">{{ __('forms.analytics.arm') }}</th>
                        <th class="py-2 text-right">{{ __('forms.ab.weight') }}</th>
                        <th class="py-2 text-right">{{ __('forms.analytics.views') }}</th>
                        <th class="py-2 text-right">{{ __('forms.analytics.submissions') }}</th>
                        <th class="py-2 text-right">{{ __('forms.analytics.conversion_rate') }}</th>
                        <th class="py-2 text-right">{{ __('forms.ab.confidence') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ab['arms'] as $arm)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-2 font-medium">
                                {{ $arm['label'] }}
                                @if ($arm['is_baseline'])
                                    <span class="ms-1 text-xs text-gray-500">({{ __('forms.ab.baseline') }})</span>
                                @endif
                                @if ($arm['is_winner'])
                                    <span class="ms-1 text-xs font-semibold text-primary-600">★</span>
                                @endif
                            </td>
                            <td class="py-2 text-right">{{ $arm['id'] === 0 ? '—' : number_format($arm['weight']) }}</td>
                            <td class="py-2 text-right">{{ number_format($arm['views']) }}</td>
                            <td class="py-2 text-right">{{ number_format($arm['submissions']) }}</td>
                            <td class="py-2 text-right">{{ number_format($arm['conversion_rate'], 1) }}%</td>
                            <td class="py-2 text-right">
                                @if ($arm['is_baseline'] || $arm['id'] === 0)
                                    —
                                @elseif ($arm['confidence'] === null)
                                    <span class="text-gray-500">{{ __('forms.ab.not_computable') }}</span>
                                @else
                                    {{ number_format($arm['confidence'], 1) }}%
                                    @if ($arm['significant'])
                                        <span class="ms-1 text-xs font-semibold text-primary-600">
                                            {{ __('forms.ab.significant', ['level' => (int) $ab['level']]) }}
                                        </span>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                </table>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                {{ __('forms.ab.min_trials', ['trials' => $this->abMinTrials()]) }}
            </p>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">{{ __('forms.analytics.recent_title') }}</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="py-2">{{ __('forms.analytics.email') }}</th>
                    <th class="py-2">{{ __('forms.analytics.source') }}</th>
                    <th class="py-2">{{ __('forms.analytics.consent') }}</th>
                    <th class="py-2">{{ __('forms.analytics.received_at') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->recentSubmissions() as $submission)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-2 font-medium">{{ $submission->email }}</td>
                        <td class="py-2">{{ $submission->source }}</td>
                        <td class="py-2">{{ $submission->consent_given ? __('forms.analytics.consent_yes') : '—' }}</td>
                        <td class="py-2">{{ $submission->created_at?->toDayDateTimeString() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-4 text-center text-gray-500">{{ __('forms.analytics.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
