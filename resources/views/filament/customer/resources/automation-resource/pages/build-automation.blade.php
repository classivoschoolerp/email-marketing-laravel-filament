{{--
    F-2.4.2-001 — §2.4.2 journey canvas.

    The graph is rendered flat: absolutely positioned node cards at the
    coordinates stored in the canvas JSON, SVG wires between them, and a
    branch "slot" chip on every edge (connected or free) that doubles as the
    add-node button and the drop target for a dragged card.

    Interaction (drag, zoom, pan, minimap) is resources/js/automation-canvas.js;
    auto-layout and undo/redo round-trip to BuildAutomation so the saved JSON
    stays authoritative.
--}}
<x-filament-panels::page>
    @php
        $cards = $this->canvasNodes();
        $edges = $this->canvasEdges();
        $counts = $this->liveCounts();
        $extent = $this->canvasExtent();
        $webhookUrl = $this->webhookUrl();

        $positions = [];

        foreach ($cards as $card) {
            $positions[$card['uid']] = ['x' => $card['x'], 'y' => $card['y']];
        }

        $canvasConfig = [
            'positions' => $positions,
            'edges' => $edges,
            'nodeWidth' => $extent['node_width'],
            'nodeHeight' => $extent['node_height'],
            'worldWidth' => $extent['width'],
            'worldHeight' => $extent['height'],
        ];
    @endphp

    @if ($webhookUrl)
        <x-filament::section compact>
            <div class="text-sm">
                <span class="font-medium">{{ __('automations.builder.webhook_hint') }}</span>
                <code class="break-all select-all" x-data x-on:click="window.getSelection().selectAllChildren($el)">{{ $webhookUrl }}</code>
            </div>
        </x-filament::section>
    @endif

    <div @if ($this->getRecord()->isActive()) wire:poll.15s @endif>
        @if ($cards !== [])
            <div
                class="emp-canvas"
                x-data="automationCanvas(@js($canvasConfig))"
                data-positions="{{ json_encode($positions) }}"
            >
                <div class="emp-canvas__bar">
                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-arrow-uturn-left"
                        wire:click="undo"
                        :disabled="! $this->canUndo()"
                    >
                        {{ __('automations.builder.canvas.undo') }}
                    </x-filament::button>

                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-arrow-uturn-right"
                        wire:click="redo"
                        :disabled="! $this->canRedo()"
                    >
                        {{ __('automations.builder.canvas.redo') }}
                    </x-filament::button>

                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-squares-plus"
                        wire:click="autoLayout"
                    >
                        {{ __('automations.builder.canvas.auto_layout') }}
                    </x-filament::button>

                    <x-filament::button size="xs" color="gray" icon="heroicon-m-magnifying-glass-plus" x-on:click="zoomIn()">
                        {{ __('automations.builder.canvas.zoom_in') }}
                    </x-filament::button>

                    <x-filament::button size="xs" color="gray" icon="heroicon-m-magnifying-glass-minus" x-on:click="zoomOut()">
                        {{ __('automations.builder.canvas.zoom_out') }}
                    </x-filament::button>

                    <x-filament::button size="xs" color="gray" icon="heroicon-m-viewfinder-circle" x-on:click="fit()">
                        {{ __('automations.builder.canvas.fit') }}
                    </x-filament::button>

                    <x-filament::button size="xs" color="gray" x-on:click="resetView()">
                        {{ __('automations.builder.canvas.reset_view') }}
                    </x-filament::button>

                    <span class="text-xs text-gray-500 dark:text-gray-400" x-text="Math.round(zoom * 100) + '%'"></span>

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('automations.builder.canvas.hint') }}
                    </span>
                </div>

                <div
                    class="emp-canvas__stage"
                    x-ref="stage"
                    x-on:wheel.prevent="onWheel($event)"
                    x-on:pointerdown="onStagePointerDown($event)"
                    x-on:pointermove="onPointerMove($event)"
                    x-on:pointerup="onPointerUp()"
                    x-on:pointerleave="onPointerUp()"
                    x-on:pointercancel="onPointerUp()"
                >
                    <div class="emp-canvas__world" x-ref="world" :style="worldStyle">
                        <svg class="emp-canvas__wires" :width="worldWidth" :height="worldHeight">
                            <template x-for="wire in wires" :key="wire.key">
                                <path class="emp-canvas__wire" :d="wire.d" />
                            </template>
                        </svg>

                        @foreach ($edges as $edge)
                            <div
                                class="emp-canvas__slot"
                                :class="isTarget(@js($edge)) && 'is-target'"
                                :style="slotStyle(@js($edge))"
                                x-on:pointerenter="enterSlot(@js($edge))"
                                x-on:pointerleave="leaveSlot(@js($edge))"
                                x-on:pointerdown.stop
                            >
                                <span>{{ $this->branchLabel($edge['branch']) }}</span>

                                <x-filament::icon-button
                                    icon="heroicon-m-plus"
                                    :label="__('automations.actions.add_node')"
                                    size="xs"
                                    color="primary"
                                    wire:click="mountAction('addNode', { parent: '{{ $edge['from'] }}', branch: '{{ $edge['branch'] }}' })"
                                />
                            </div>
                        @endforeach

                        @foreach ($cards as $card)
                            @include('filament.customer.resources.automation-resource.pages.partials.flow-node', [
                                'node' => $card,
                                'counts' => $counts,
                            ])
                        @endforeach
                    </div>

                    <div
                        class="emp-canvas__minimap"
                        x-ref="minimap"
                        x-on:pointerdown.stop="minimapJump($event)"
                        aria-label="{{ __('automations.builder.canvas.minimap') }}"
                    >
                        <template x-for="uid in nodeUids" :key="uid">
                            <div class="emp-canvas__minidot" :style="miniStyle(uid)"></div>
                        </template>

                        <div class="emp-canvas__miniview" :style="miniViewportStyle"></div>
                    </div>
                </div>
            </div>

            @vite('resources/js/automation-canvas.js')
        @else
            <x-filament::section>
                <div class="text-sm text-center py-8">
                    {{ __('automations.validation.empty') }}
                </div>
            </x-filament::section>
        @endif
    </div>

    @if ($this->simulationSteps !== [])
        <x-filament::section :heading="__('automations.builder.simulate_result')" collapsible>
            <ol class="space-y-2 text-sm">
                @foreach ($this->simulationSteps as $index => $step)
                    <li class="flex items-start gap-2">
                        <x-filament::badge :color="match ($step['result']) {
                            'error' => 'danger',
                            'completed' => 'success',
                            'exited' => 'warning',
                            default => 'gray',
                        }">
                            {{ $index + 1 }}
                        </x-filament::badge>
                        <div>
                            <span class="font-medium">
                                {{ $step['type'] === '_end'
                                    ? __('automations.builder.end_of_branch')
                                    : (\App\Enums\AutomationNodeType::tryFrom($step['type'])?->label() ?? $step['type']) }}
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                — {{ __('automations.builder.simulate_step.'.$step['result']) }}
                                @if ($step['branch'] !== null && $step['branch'] !== 'next')
                                    ({{ $step['branch'] }})
                                @endif
                            </span>
                            @if ($step['detail'] !== [])
                                <div class="text-xs text-gray-500 dark:text-gray-400 break-all">
                                    {{ json_encode($step['detail'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
