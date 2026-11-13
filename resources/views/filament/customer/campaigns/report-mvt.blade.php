{{-- §2.3.6 F-2.3.6-004 — per-FACTOR results for a multivariate test.

     Marginal pools: every variant carrying an option is summed across the
     other factors, which is what makes "subject B wins whatever the image is"
     answerable. The multiple-comparison caveat is stated in the caption and
     applied to the verdicts (Holm–Bonferroni) — see MultivariateAnalysis. --}}
@if ($effects['available'] && $effects['factors'] !== [])
    @php($alpha = $effects['alpha'])
    @php($m = max(1, $effects['comparisons']))

    <x-filament::section :heading="__('campaigns.mvt.report.title')" class="mt-6">
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('campaigns.mvt.report.correction', [
                'comparisons' => $effects['comparisons'],
                'alpha' => round($alpha * 100, 2),
                'inflated' => round((1 - (1 - $alpha) ** $m) * 100, 1),
            ]) }}
        </p>

        @foreach ($effects['factors'] as $factor)
            <div class="mb-4">
                <p class="mb-1 text-sm font-semibold">{{ $factor['label'] }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="py-1 pr-3">{{ __('campaigns.mvt.report.level') }}</th>
                                <th class="py-1 pr-3">{{ __('campaigns.mvt.report.variants') }}</th>
                                <th class="py-1 pr-3">{{ __('campaigns.mvt.report.successes') }}</th>
                                <th class="py-1 pr-3">{{ __('campaigns.mvt.report.trials') }}</th>
                                <th class="py-1 pr-3">{{ __('campaigns.mvt.report.rate') }}</th>
                                <th class="py-1">p</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($factor['levels'] as $level)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1 pr-3">
                                        <span class="font-medium">{{ \Illuminate\Support\Str::limit((string) $level['level'], 60) }}</span>
                                        @if ($level['is_best'])
                                            <span class="ml-1 rounded bg-success-100 px-1.5 py-0.5 text-xs text-success-700">
                                                {{ __('campaigns.mvt.report.best') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-3 text-xs text-gray-500 dark:text-gray-400">
                                        {{ implode(', ', $level['variants']) }}
                                    </td>
                                    <td class="py-1 pr-3 tabular-nums">{{ number_format($level['successes']) }}</td>
                                    <td class="py-1 pr-3 tabular-nums">{{ number_format($level['trials']) }}</td>
                                    <td class="py-1 pr-3 tabular-nums">{{ $level['rate'] }}%</td>
                                    <td class="py-1 text-xs">
                                        @if ($level['is_best'])
                                            —
                                        @elseif (! $level['computable'])
                                            <span class="text-gray-500 dark:text-gray-400">
                                                {{ __('campaigns.mvt.report.not_computable') }}
                                            </span>
                                        @elseif ($level['significant'])
                                            <span class="text-success-600">
                                                {{ number_format((float) $level['p_value'], 6) }} —
                                                {{ __('campaigns.mvt.report.significant') }}
                                            </span>
                                        @elseif ($level['significant_uncorrected'])
                                            <span class="text-warning-600">
                                                {{ number_format((float) $level['p_value'], 6) }} —
                                                {{ __('campaigns.mvt.report.uncorrected_only', ['comparisons' => $effects['comparisons']]) }}
                                            </span>
                                        @else
                                            <span class="text-gray-500 dark:text-gray-400">
                                                {{ number_format((float) $level['p_value'], 6) }} —
                                                {{ __('campaigns.mvt.report.not_significant') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </x-filament::section>
@endif
