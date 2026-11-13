{{-- Who-clicked-what drill-down (spec §2.3.8 F-2.3.8-005). --}}
<div class="max-h-96 overflow-x-auto overflow-y-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                <th class="py-2 pr-4">{{ __('reports.columns.subscriber') }}</th>
                <th class="py-2 pr-4">{{ __('reports.columns.clicks') }}</th>
                <th class="py-2">{{ __('reports.columns.last_click') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clickers as $clicker)
                <tr class="border-b border-gray-100 dark:border-white/5">
                    <td class="py-2 pr-4">{{ $clicker->subscriber_email ?? $clicker->subscriber_uid }}</td>
                    <td class="py-2 pr-4">{{ number_format((int) $clicker->clicks_count) }}</td>
                    <td class="py-2">{{ $clicker->last_click_at }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="py-3 text-gray-500 dark:text-gray-400">{{ __('reports.empty.clickers') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
