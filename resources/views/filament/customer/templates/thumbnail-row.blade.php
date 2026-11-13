@php
    /** @var \App\Models\EmailTemplate $record */
    $record = $getRecord();
@endphp

{{-- List-row thumbnail (spec §2.5.1 F-2.5.1-001). --}}
<img
    src="{{ $record->thumbnailUrl() }}"
    alt="{{ __('templates.thumbnail.alt', ['name' => $record->name]) }}"
    loading="lazy"
    class="h-12 w-16 rounded object-cover object-top bg-gray-100 dark:bg-gray-800"
/>
