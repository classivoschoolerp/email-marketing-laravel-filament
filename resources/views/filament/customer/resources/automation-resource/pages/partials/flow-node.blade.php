@php
    /** @var array{uid: string, type: \App\Enums\AutomationNodeType, label: ?string, summary: string, x: int, y: int} $node */
    $type = $node['type'];
    $nodeCounts = $counts[$node['uid']] ?? null;
    $badgeColor = $type->isTrigger() ? 'success' : ($type->isAction() ? 'info' : 'warning');
@endphp

{{--
    One absolutely positioned canvas card (F-2.4.2-001). The card body is the
    drag handle; the tool buttons stop propagation so clicking edit/delete
    never starts a drag.
--}}
<div
    class="emp-canvas__node"
    :class="isDragging('{{ $node['uid'] }}') && 'is-dragging'"
    :style="nodeStyle('{{ $node['uid'] }}')"
    x-on:pointerdown.stop="startNodeDrag($event, '{{ $node['uid'] }}')"
    wire:key="canvas-node-{{ $node['uid'] }}"
>
    <div class="flex items-center justify-between gap-2">
        <x-filament::badge :color="$badgeColor" size="xs">
            {{ __('automations.node_group.'.($type->isTrigger() ? 'trigger' : ($type->isAction() ? 'action' : 'logic'))) }}
        </x-filament::badge>

        <div class="emp-canvas__node-tools" x-on:pointerdown.stop>
            <x-filament::icon-button
                icon="heroicon-m-pencil-square"
                :label="__('automations.actions.edit_node')"
                size="xs"
                color="gray"
                wire:click="mountAction('editNode', { uid: '{{ $node['uid'] }}' })"
            />

            @unless ($type->isTrigger())
                <x-filament::icon-button
                    icon="heroicon-m-trash"
                    :label="__('automations.actions.delete_node')"
                    size="xs"
                    color="danger"
                    wire:click="mountAction('deleteNode', { uid: '{{ $node['uid'] }}' })"
                />
            @endunless
        </div>
    </div>

    <div class="emp-canvas__node-title">
        {{ $node['label'] ?? $type->label() }}
    </div>

    @if ($node['summary'] !== '')
        <div class="emp-canvas__node-summary">{{ $node['summary'] }}</div>
    @endif

    @if ($nodeCounts !== null)
        <div class="emp-canvas__node-summary">
            {{ __('automations.builder.contacts_here', ['count' => $nodeCounts['active']]) }}
            · {{ __('automations.builder.reached', ['count' => $nodeCounts['reached']]) }}
        </div>
    @endif
</div>
