@php
    /** @var \App\Models\EmailTemplate $record */
    $record = $getRecord();
@endphp

{{-- Grid-card thumbnail (spec §2.5.1 F-2.5.1-001). --}}
<div class="w-full overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800" style="aspect-ratio: 4 / 3;">
    <img
        src="{{ $record->thumbnailUrl() }}"
        alt="{{ __('templates.thumbnail.alt', ['name' => $record->name]) }}"
        loading="lazy"
        class="h-full w-full object-cover object-top"
    />
</div>
