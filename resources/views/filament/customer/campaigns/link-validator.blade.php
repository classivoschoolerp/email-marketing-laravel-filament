{{-- §2.3.5 F-2.3.5-005 — link validator. Honest four-state classification. --}}
@if ($result['total'] === 0)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('campaigns.link_validator.none') }}</p>
@else
    <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('campaigns.link_validator.summary', ['ok' => $result['ok'], 'total' => $result['total']]) }}
    </p>

    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="py-1 pr-3">{{ __('campaigns.link_validator.status') }}</th>
                    <th class="py-1">{{ __('campaigns.link_validator.url') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result['links'] as $link)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="py-1 pr-3 whitespace-nowrap">
                            <span @class([
                                'text-success-600' => $link['status'] === 'ok',
                                'text-danger-600' => $link['status'] === 'broken',
                                'text-warning-600' => $link['status'] === 'unreachable',
                                'text-gray-400' => $link['status'] === 'skipped',
                            ])>
                                {{ __('campaigns.link_validator.'.$link['status']) }}@if ($link['code']) ({{ $link['code'] }})@endif
                            </span>
                        </td>
                        <td class="py-1 break-all text-gray-600 dark:text-gray-300">
                            {{ $link['url'] }}
                            @if ($link['reason'])
                                <span class="block text-xs text-gray-400">{{ $link['reason'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
