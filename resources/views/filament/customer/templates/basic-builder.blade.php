{{--
    Basic / simple built-in builder (spec §2.5.3 F-2.5.3-001, WP-27B).

    Server-rendered block list; every mutation is a Livewire call, so there is
    no client-side document model and NO new JS dependency. Reordering uses
    native HTML5 drag-and-drop wired with the Alpine that Filament already
    ships — `draggable`, `dragstart`, `dragover`, `drop` — plus keyboard-
    reachable move buttons so the feature is not mouse-only.

    Shared verbatim by the template page and the campaign page.
--}}
<x-filament-panels::page>
    @php($types = $this->blockTypeOptions())

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- ---------------------------------------------------------- --}}
        {{-- Editor column                                              --}}
        {{-- ---------------------------------------------------------- --}}
        <div class="space-y-4">
            <x-filament::section :heading="__('templates.basic_builder.blocks_heading')">
                <div class="mb-4 flex flex-wrap gap-2">
                    @foreach ($types as $type => $label)
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-m-plus"
                            wire:click="addBlock('{{ $type }}')"
                            wire:key="add-{{ $type }}"
                        >
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>

                <div
                    x-data="{ from: null }"
                    class="space-y-2"
                >
                    @forelse ($this->blocks as $index => $block)
                        <div
                            wire:key="block-{{ $block['id'] }}"
                            draggable="true"
                            x-on:dragstart="from = {{ $index }}; $event.dataTransfer.effectAllowed = 'move'"
                            x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'"
                            x-on:drop.prevent="if (from !== null && from !== {{ $index }}) { $wire.moveBlockTo(from, {{ $index }}) } from = null"
                            class="rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="cursor-move text-gray-400" aria-hidden="true">⋮⋮</span>
                                    <span class="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                        {{ $types[$block['type']] ?? $block['type'] }}
                                    </span>
                                    <span class="truncate text-sm text-gray-600 dark:text-gray-300">
                                        @if ($block['type'] === 'text')
                                            {{ \Illuminate\Support\Str::limit($block['content'], 48) }}
                                        @elseif ($block['type'] === 'button')
                                            {{ $block['label'] }}
                                        @elseif ($block['type'] === 'image')
                                            {{ $block['src'] !== '' ? $block['src'] : __('templates.basic_builder.empty_image') }}
                                        @endif
                                    </span>
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <x-filament::icon-button
                                        icon="heroicon-m-arrow-up"
                                        size="sm"
                                        color="gray"
                                        :label="__('templates.basic_builder.actions.move_up')"
                                        wire:click="moveBlockUp({{ $index }})"
                                        :disabled="$index === 0"
                                    />
                                    <x-filament::icon-button
                                        icon="heroicon-m-arrow-down"
                                        size="sm"
                                        color="gray"
                                        :label="__('templates.basic_builder.actions.move_down')"
                                        wire:click="moveBlockDown({{ $index }})"
                                        :disabled="$index === count($this->blocks) - 1"
                                    />
                                    <x-filament::icon-button
                                        icon="heroicon-m-pencil-square"
                                        size="sm"
                                        color="gray"
                                        :label="__('templates.basic_builder.actions.edit')"
                                        wire:click="editBlock({{ $this->editing === $index ? 'null' : $index }})"
                                    />
                                    <x-filament::icon-button
                                        icon="heroicon-m-document-duplicate"
                                        size="sm"
                                        color="gray"
                                        :label="__('templates.basic_builder.actions.duplicate')"
                                        wire:click="duplicateBlock({{ $index }})"
                                    />
                                    <x-filament::icon-button
                                        icon="heroicon-m-trash"
                                        size="sm"
                                        color="danger"
                                        :label="__('templates.basic_builder.actions.remove')"
                                        wire:click="removeBlock({{ $index }})"
                                    />
                                </div>
                            </div>

                            @if ($this->editing === $index)
                                <div class="mt-3 grid grid-cols-1 gap-3 border-t border-gray-100 pt-3 sm:grid-cols-2 dark:border-white/5">
                                    @include('filament.customer.templates.basic-builder-fields', [
                                        'block' => $block,
                                        'index' => $index,
                                    ])
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('templates.basic_builder.empty') }}
                        </p>
                    @endforelse
                </div>

                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('templates.basic_builder.hint') }}
                </p>
            </x-filament::section>
        </div>

        {{-- ---------------------------------------------------------- --}}
        {{-- Live preview column: the exact HTML that will be stored.   --}}
        {{-- ---------------------------------------------------------- --}}
        <div>
            <x-filament::section :heading="__('templates.basic_builder.preview_heading')">
                <iframe
                    title="{{ __('templates.basic_builder.preview_heading') }}"
                    sandbox=""
                    class="h-[70vh] w-full rounded-lg border border-gray-200 bg-white dark:border-white/10"
                    srcdoc="{{ $this->renderHtml() }}"
                ></iframe>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
