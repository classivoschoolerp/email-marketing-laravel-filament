<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $perNode = $this->perNode();
        $errors = $this->errors();
        $drilldown = $this->drilldown();
        $moneyFormatter = fn (int $minor): string => number_format($minor / 100, 2);
    @endphp

    {{-- Summary (F-2.4.3-004 conversion + revenue included) --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-6">
        @foreach ([
            'entered' => $summary['entered'],
            'active' => $summary['active'],
            'completed' => $summary['completed'],
            'converted' => $summary['converted'],
            'conversion_rate' => $summary['conversion_rate'] !== null ? $summary['conversion_rate'].'%' : '—',
            'revenue' => $moneyFormatter($summary['revenue']),
        ] as $key => $value)
            <x-filament::section compact>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('automations.report.summary.'.$key) }}</div>
                <div class="text-xl font-semibold">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- Funnel + per-node stats (F-2.4.3-001/002) --}}
    <x-filament::section :heading="__('automations.report.per_node')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-start">
                <thead>
                    <tr class="text-xs text-gray-500 dark:text-gray-400 text-start">
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.node') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.reached') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.funnel') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.active') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.sent') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.opened') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.clicked') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.converted') }}</th>
                        <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.dropped') }}</th>
                        <th class="py-2 text-start"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($perNode as $row)
                        @php($node = $row['node'])
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pe-4">
                                <div class="font-medium">{{ $node->label ?? $node->type->label() }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $node->type->label() }}</div>
                            </td>
                            <td class="py-2 pe-4">{{ $row['reached'] }}</td>
                            <td class="py-2 pe-4 w-40">
                                <div class="h-2 rounded bg-gray-100 dark:bg-white/10 overflow-hidden" style="min-width: 6rem;">
                                    <div class="h-2 rounded bg-primary-500" style="width: {{ min(100, $row['reached_pct'] ?? 0) }}%"></div>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['reached_pct'] !== null ? $row['reached_pct'].'%' : '—' }}</div>
                            </td>
                            <td class="py-2 pe-4">{{ $row['active'] }}</td>
                            <td class="py-2 pe-4">{{ $row['sent'] }}</td>
                            <td class="py-2 pe-4">{{ $row['opened'] }}</td>
                            <td class="py-2 pe-4">{{ $row['clicked'] }}</td>
                            <td class="py-2 pe-4">{{ $row['converted'] }}</td>
                            <td class="py-2 pe-4">{{ $row['dropped'] }}</td>
                            <td class="py-2">
                                @if ($row['active'] > 0)
                                    <x-filament::link
                                        tag="button"
                                        size="sm"
                                        wire:click="$set('drilldownNode', '{{ $node->uid }}')"
                                    >
                                        {{ __('automations.actions.view_contacts') }}
                                    </x-filament::link>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- Contacts at step drill-down (F-2.4.3-005) --}}
    @if ($this->drilldownNode !== null)
        <x-filament::section :heading="__('automations.report.contacts_at')" collapsible>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.subscriber') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.entered_at') }}</th>
                            <th class="py-2 text-start">{{ __('automations.report.columns.wake_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($drilldown as $run)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2 pe-4">{{ $run->subscriber?->email ?? '—' }}</td>
                                <td class="py-2 pe-4">{{ $run->entered_at?->diffForHumans() }}</td>
                                <td class="py-2">{{ $run->wake_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-2" colspan="3">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- Error log (F-2.4.3-006) --}}
    <x-filament::section :heading="__('automations.report.errors')" collapsible>
        @if ($errors->isEmpty())
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('automations.report.no_errors') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.kind') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.node') }}</th>
                            <th class="py-2 pe-4 text-start">{{ __('automations.report.columns.message') }}</th>
                            <th class="py-2 text-start">{{ __('automations.report.columns.at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($errors as $error)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2 pe-4">
                                    <x-filament::badge :color="$error['kind'] === 'webhook' ? 'warning' : 'danger'">
                                        {{ $error['kind'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2 pe-4">
                                    {{ $error['node_type'] !== null ? (\App\Enums\AutomationNodeType::tryFrom($error['node_type'])?->label() ?? $error['node_type']) : '—' }}
                                </td>
                                <td class="py-2 pe-4 break-all">{{ $error['message'] }}</td>
                                <td class="py-2 whitespace-nowrap">{{ $error['at']?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
