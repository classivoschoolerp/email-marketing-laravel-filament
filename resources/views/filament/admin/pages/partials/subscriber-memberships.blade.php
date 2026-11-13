{{-- F-3.8-002 every list an address is on, system-wide (WP-39) --}}
<div class="space-y-2">
    <p class="text-sm font-medium">{{ $email }}</p>

    @forelse ($rows as $row)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
            <div>
                <p class="font-medium">{{ $row->list?->name ?? '—' }}</p>
                <p class="text-gray-500">{{ $row->customer?->email ?? '—' }}</p>
            </div>
            <div class="flex items-center gap-3">
                <x-filament::badge :color="$row->status->color()">{{ $row->status->label() }}</x-filament::badge>
                @if ($row->trashed())
                    <x-filament::badge color="gray">{{ __('dashboards.subscribers_overview.deleted') }}</x-filament::badge>
                @endif
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('dashboards.subscribers_overview.no_memberships') }}</p>
    @endforelse
</div>
