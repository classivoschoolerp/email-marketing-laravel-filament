{{--
    Per-block settings for the basic builder (F-2.5.3-001). Plain inputs bound
    with wire:model.blur — every change round-trips through
    EditsBasicBlocks::updatedBlocks(), which re-normalises the whole document
    and re-renders the HTML server-side.
--}}
@php($alignments = ['left' => __('templates.basic_builder.align.left'), 'center' => __('templates.basic_builder.align.center'), 'right' => __('templates.basic_builder.align.right')])

@if ($block['type'] === 'text')
    <label class="sm:col-span-2 block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.content') }}</span>
        <textarea
            rows="4"
            wire:model.blur="blocks.{{ $index }}.content"
            class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100"
        ></textarea>
        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('templates.basic_builder.fields.content_help') }}</span>
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.align') }}</span>
        <select wire:model.blur="blocks.{{ $index }}.align" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
            @foreach ($alignments as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.font_size') }}</span>
        <input type="number" min="9" max="48" wire:model.blur="blocks.{{ $index }}.font_size"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.color') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.color" placeholder="#111827"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>
@elseif ($block['type'] === 'image')
    <label class="sm:col-span-2 block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.src') }}</span>
        <input type="url" wire:model.blur="blocks.{{ $index }}.src" placeholder="https://…"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.alt') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.alt"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.href') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.href" placeholder="https://…"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.width') }}</span>
        <input type="number" min="40" max="600" wire:model.blur="blocks.{{ $index }}.width"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.align') }}</span>
        <select wire:model.blur="blocks.{{ $index }}.align" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
            @foreach ($alignments as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
@elseif ($block['type'] === 'button')
    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.label') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.label"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.href') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.href" placeholder="https://…"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.background') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.background" placeholder="#2563eb"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.color') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.color" placeholder="#ffffff"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.align') }}</span>
        <select wire:model.blur="blocks.{{ $index }}.align" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
            @foreach ($alignments as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
@elseif ($block['type'] === 'rss')
    {{-- §2.3.3 F-2.3.3-011 / F-2.3.3-024 — RSS-to-email block. --}}
    <label class="sm:col-span-2 block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.url') }}</span>
        <input type="url" wire:model.blur="blocks.{{ $index }}.url" placeholder="https://example.com/feed.xml"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.items') }}</span>
        <input type="number" min="1" max="20" wire:model.blur="blocks.{{ $index }}.items"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.color') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.color" placeholder="#111827"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" wire:model.blur="blocks.{{ $index }}.show_date" class="rounded border-gray-300">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.show_date') }}</span>
    </label>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" wire:model.blur="blocks.{{ $index }}.show_excerpt" class="rounded border-gray-300">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.show_excerpt') }}</span>
    </label>
@elseif ($block['type'] === 'countdown')
    {{-- §2.3.3 F-2.3.3-012 — countdown timer block. --}}
    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.until') }}</span>
        <input type="datetime-local" wire:model.blur="blocks.{{ $index }}.until"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.countdown_label') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.label"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.expired_label') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.expired_label"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.background') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.background" placeholder="#f3f4f6"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.color') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.color" placeholder="#111827"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.align') }}</span>
        <select wire:model.blur="blocks.{{ $index }}.align" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
            @foreach ($alignments as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
@else
    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.color') }}</span>
        <input type="text" wire:model.blur="blocks.{{ $index }}.color" placeholder="#e5e7eb"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>

    <label class="block text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('templates.basic_builder.fields.thickness') }}</span>
        <input type="number" min="1" max="20" wire:model.blur="blocks.{{ $index }}.thickness"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
    </label>
@endif
