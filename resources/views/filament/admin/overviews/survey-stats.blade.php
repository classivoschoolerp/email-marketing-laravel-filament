{{-- §3.9 F-3.9-001 "stats access" (WP-40). Rendered from SurveyStats, the
     §2.8 owner — the operator sees exactly what the customer sees. --}}
<div class="space-y-4">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('reports.overviews.surveys.responders') }}
            </div>
            <div class="text-xl font-semibold" data-testid="survey-responders">{{ number_format($stats['responders']) }}</div>
        </div>
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
            <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('reports.overviews.surveys.completed') }}
            </div>
            <div class="text-xl font-semibold" data-testid="survey-completed">{{ number_format($stats['completed']) }}</div>
        </div>
    </div>

    @if ($stats['questions'] === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.overviews.surveys.stats_empty') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[420px] text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-3">{{ __('reports.overviews.surveys.stats_question') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('reports.overviews.surveys.stats_answered') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('reports.overviews.surveys.stats_average') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stats['questions'] as $question)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3">{{ $question['label'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($question['answered']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ $question['average'] === null ? '—' : $question['average'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
