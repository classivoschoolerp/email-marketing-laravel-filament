{{-- Confirmation-step settings summary (spec §2.3.5 F-2.3.5-001), built
     from the live wizard state. --}}
@php
    /** @var \App\Models\Campaign $campaign */
    $rows = array_filter([
        __('campaigns.fields.name') => $campaign->name,
        __('campaigns.fields.type') => $campaign->type?->label(),
        __('campaigns.fields.group') => $groupName,
        __('campaigns.fields.list') => $listName,
        __('campaigns.fields.segment') => $segmentName,
        __('campaigns.fields.from_name') => $campaign->from_name,
        __('campaigns.fields.from_email') => $campaign->from_email,
        __('campaigns.fields.subject') => $campaign->subject,
        __('campaigns.fields.preheader') => $campaign->preheader,
        __('campaigns.fields.send_at') => $campaign->send_immediately
            ? __('campaigns.fields.send_immediately')
            : $campaign->send_at?->format('Y-m-d H:i').($campaign->timezone ? ' ('.$campaign->timezone.')' : ''),
        __('campaigns.autoresponder.trigger.label') => $campaign->ar_trigger?->label(),
        __('campaigns.recurring.interval.label') => $campaign->rec_interval?->label(),
    ], fn ($value): bool => $value !== null && $value !== '' && $value !== false);
@endphp
<dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
    @foreach ($rows as $label => $value)
        <div class="flex justify-between gap-4 border-b border-gray-100 py-1.5 text-sm dark:border-gray-800">
            <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
            <dd class="text-right font-medium text-gray-900 dark:text-white">{{ $value }}</dd>
        </div>
    @endforeach
</dl>
