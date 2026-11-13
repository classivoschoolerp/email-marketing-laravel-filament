{{-- Merge-tag inserter panel (spec §2.3.3 F-2.3.3-007): enabled template
     tags + the target list's subscriber field tags. Click copies the tag to
     the clipboard for pasting into any content field. --}}
<div class="flex flex-wrap gap-1.5" x-data>
    @forelse ($tags as $tag)
        <button
            type="button"
            class="rounded-md bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700 ring-1 ring-gray-200 hover:bg-primary-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
            x-on:click="navigator.clipboard && navigator.clipboard.writeText('{{ '{'.$tag.'}' }}')"
            title="{{ __('campaigns.builder_page.merge_tags') }}"
        >{{ '{'.$tag.'}' }}</button>
    @empty
        <span class="text-sm text-gray-500">—</span>
    @endforelse
</div>
