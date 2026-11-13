{{-- §2.3.6 F-2.3.6-009 comparison table + chart, F-2.3.6-010/011 significance. --}}
@php($campaign = $this->getRecord())

@if ($campaign->ab_enabled && $variants !== [])
    @php($max = max(array_map(fn ($row) => $row['criterion_value'], $variants)) ?: 1)

    <x-filament::section :heading="__('campaigns.ab.report.title')" class="mt-6">
        {{-- State line: waiting, or the decision that was made. --}}
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
            @if ($campaign->ab_winner_variant_id)
                {{ __('campaigns.ab.report.decided', [
                    'label' => optional($campaign->winnerVariant)->label ?? '?',
                    'decision' => __('campaigns.ab.decision.'.($campaign->ab_winner_decision === 'fallback' ? 'window' : $campaign->ab_winner_decision)),
                    'date' => optional($campaign->ab_winner_decided_at)->toDayDateTimeString(),
                ]) }}
                @if ($campaign->ab_winner_p_value !== null)
                    {{ __('campaigns.ab.report.confidence', [
                        'percent' => number_format((1 - (float) $campaign->ab_winner_p_value) * 100, 2),
                        'p' => number_format((float) $campaign->ab_winner_p_value, 6),
                    ]) }}
                @endif
            @elseif ($campaign->ab_test_finished_at)
                {{ __('campaigns.ab.report.awaiting') }}
            @endif
        </p>

        {{-- Live significance read-out (leader vs runner-up). --}}
        @if ($significance !== null && $campaign->ab_winner_variant_id === null)
            <p class="mb-3 text-sm">
                @if (! $significance->computable)
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ __('campaigns.ab.report.not_computable', ['min' => \App\Services\Campaigns\AbTest\SignificanceTest::MIN_TRIALS]) }}
                    </span>
                @elseif ($significance->isSignificantAt((float) $campaign->ab_confidence_level))
                    <span class="text-success-600">
                        {{ __('campaigns.ab.report.confidence', [
                            'percent' => number_format($significance->confidencePercent(), 2),
                            'p' => number_format($significance->pValue, 6),
                        ]) }}
                    </span>
                @else
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ __('campaigns.ab.report.not_significant', ['level' => (int) $campaign->ab_confidence_level]) }}
                        {{ __('campaigns.ab.report.confidence', [
                            'percent' => number_format($significance->confidencePercent(), 2),
                            'p' => number_format($significance->pValue, 6),
                        ]) }}
                    </span>
                @endif
            </p>
        @endif

        {{-- WP-45: the criterion is measurable in the product, but this
             CAMPAIGN may still have no source for it (goal tracking off and
             no recorded conversion) — say so instead of ranking zeros. --}}
        @php($abCriterion = \App\Enums\AbWinnerCriteria::from($campaign->ab_winner_criteria))
        @if (! $abCriterion->isAvailable() || ! $abCriterion->isAvailableFor($campaign))
            <p class="mb-3 text-sm text-warning-600">{{ __('campaigns.ab.report.criteria_unavailable') }}</p>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.variant') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.recipients') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.delivered') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.unique_opens') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.open_rate') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.unique_clicks') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.click_rate') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.ctor') }}</th>
                        <th class="py-1 pr-3">{{ __('campaigns.ab.report.unsubscribes') }}</th>
                        <th class="py-1">{{ __('campaigns.ab.report.criterion') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($variants as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-1 pr-3 font-semibold">
                                {{ $row['label'] }}
                                @if ($row['is_winner'])
                                    <span class="ml-1 rounded bg-success-100 px-1.5 py-0.5 text-xs text-success-700">{{ __('campaigns.ab.report.winner') }}</span>
                                @endif
                            </td>
                            <td class="py-1 pr-3">{{ number_format($row['recipients']) }}</td>
                            <td class="py-1 pr-3">{{ number_format($row['delivered']) }}</td>
                            <td class="py-1 pr-3">{{ number_format($row['unique_opens']) }}</td>
                            <td class="py-1 pr-3">{{ $row['open_rate'] }}%</td>
                            <td class="py-1 pr-3">{{ number_format($row['unique_clicks']) }}</td>
                            <td class="py-1 pr-3">{{ $row['click_rate'] }}%</td>
                            <td class="py-1 pr-3">{{ $row['ctor'] }}%</td>
                            <td class="py-1 pr-3">{{ number_format($row['unsubscribes']) }}</td>
                            <td class="py-1">
                                {{-- The "chart": a proportional bar per variant on the
                                     deciding criterion. Inline SVG-free so it prints. --}}
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-24 rounded bg-gray-100 dark:bg-gray-800">
                                        <div class="h-2 rounded bg-primary-500"
                                             style="width: {{ (int) round($row['criterion_value'] * 100 / $max) }}%"></div>
                                    </div>
                                    <span class="tabular-nums">{{ $row['criterion_value'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- F-2.3.6-007 "or manual". --}}
        @if ($campaign->ab_winner_variant_id === null && $campaign->ab_test_finished_at !== null)
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($variants as $row)
                    <x-filament::button size="sm" color="gray" wire:click="chooseWinner({{ $row['variant_id'] }})">
                        {{ $row['label'] }} — {{ __('campaigns.ab.report.pick_winner') }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif
    </x-filament::section>
@endif
