{{-- Template preview with sample merge data (spec §2.5.1 / §2.5.5 F-2.5.5-005).
     Rendered inside a sandboxed iframe so template CSS cannot leak into the panel. --}}
@if (trim((string) $html) === '')
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('templates.preview.empty') }}</p>
@else
    <iframe
        srcdoc="{{ $html }}"
        sandbox=""
        referrerpolicy="no-referrer"
        class="w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
        style="height: 70vh;"
    ></iframe>
@endif
