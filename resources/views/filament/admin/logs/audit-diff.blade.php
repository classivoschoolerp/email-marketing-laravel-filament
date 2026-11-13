{{-- §3.27 F-3.27-011 before/after diff (WP-40). Values are already redacted
     and truncated by ActivityLogObserver — this view only renders. --}}
@php($diff = $log->diff())
@php($legacyChanged = (array) (($log->properties ?? [])['changed'] ?? []))

<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        @foreach ([
            'reports.overviews.columns.customer' => $log->customer?->email,
            'reports.admin.audit.actor' => $log->actorLabel(),
            'reports.admin.audit.action' => $log->actionLabel(),
            'reports.admin.audit.subject' => $log->subject_label,
            'reports.columns.date' => (string) $log->created_at,
            'reports.columns.ip' => $log->ip,
        ] as $key => $value)
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __($key) }}</dt>
                <dd class="mt-1 break-words font-medium">{{ $value === null || $value === '' ? '—' : $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($log->description)
        <p class="text-gray-600 dark:text-gray-300">{{ $log->description }}</p>
    @endif

    <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('reports.admin.audit.diff_heading') }}
        </h3>

        @if ($diff === [])
            @if ($legacyChanged !== [])
                <p class="text-gray-500 dark:text-gray-400" data-testid="audit-legacy">
                    {{ __('reports.admin.audit.legacy_diff', ['fields' => implode(', ', $legacyChanged)]) }}
                </p>
            @else
                <p class="text-gray-500 dark:text-gray-400" data-testid="audit-no-diff">
                    {{ __('reports.admin.audit.no_diff') }}
                </p>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[480px] text-left text-sm" data-testid="audit-diff">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-3">{{ __('reports.admin.audit.field') }}</th>
                            <th class="py-2 pr-3">{{ __('reports.admin.audit.old') }}</th>
                            <th class="py-2 pr-3">{{ __('reports.admin.audit.new') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($diff as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                                <td class="py-2 pr-3 font-mono text-xs">{{ $row['field'] }}</td>
                                @foreach (['old', 'new'] as $side)
                                    <td class="py-2 pr-3 break-words {{ $side === 'old' ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                        @php($value = $row[$side])
                                        @if ($value === \App\Observers\ActivityLogObserver::REDACTED)
                                            <span class="italic text-gray-500">{{ __('reports.admin.audit.redacted') }}</span>
                                        @elseif ($value === null || $value === '')
                                            <span class="text-gray-400">{{ __('reports.admin.audit.empty_value') }}</span>
                                        @elseif (is_bool($value))
                                            {{ $value ? '1' : '0' }}
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
