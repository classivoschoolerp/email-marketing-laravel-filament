{{-- §2.3.5 F-2.3.5-004 — SpamAssassin score with rule breakdown (D-08/D-49). --}}
@if (! $result->checked)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('campaigns.spam_check.unavailable') }}
        @if ($result->error)
            <span class="block mt-1 text-xs">{{ $result->error }}</span>
        @endif
    </p>
@else
    <p class="text-sm font-semibold {{ $result->blocked ? 'text-danger-600' : 'text-success-600' }}">
        {{ __('campaigns.spam_check.score', [
            'score' => number_format($result->score, 1),
            'required' => number_format($result->requiredScore, 1),
        ]) }}
    </p>

    @if ($result->rules === [])
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('campaigns.spam_check.clean') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="mt-3 w-full text-left text-sm">
            <thead>
                <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="py-1 pr-3">{{ __('campaigns.spam_check.points') }}</th>
                    <th class="py-1 pr-3">{{ __('campaigns.spam_check.rule') }}</th>
                    <th class="py-1">{{ __('campaigns.spam_check.description') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rules as $rule)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1 pr-3 font-mono">{{ number_format($rule['points'], 1) }}</td>
                        <td class="py-1 pr-3 font-mono text-xs">{{ $rule['rule'] }}</td>
                        <td class="py-1 text-gray-600 dark:text-gray-300">{{ $rule['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
    @endif
@endif
