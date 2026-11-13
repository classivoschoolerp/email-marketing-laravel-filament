/**
 * F-2.4.2-001 — automation builder canvas interactions (spec §2.4.2).
 *
 * Hand-rolled Alpine component, zero new npm dependencies: everything below
 * is Pointer Events + CSS transforms. Registered as a plain global factory
 * (not through `alpine:init`) because Filament boots Alpine on
 * DOMContentLoaded, after every deferred module in the page body has run —
 * so `x-data="automationCanvas(...)"` always resolves.
 *
 * Responsibilities (the browser half of the row; the server half lives in
 * BuildAutomation + CanvasLayout):
 *  - drag a node card, drop it on empty canvas → $wire.updateNodePositions()
 *  - drag a node card onto a branch slot  → $wire.moveNodeToBranch()
 *  - wheel / +− buttons zoom about the pointer, drag background to pan,
 *    "fit" frames the whole graph
 *  - minimap mirrors node positions and the visible viewport; clicking it
 *    recentres the view
 *
 * Auto-layout and undo/redo are server round-trips (wire:click) so the saved
 * canvas JSON is always the single source of truth.
 */

import '../css/automation-canvas.css';

const MIN_ZOOM = 0.3;
const MAX_ZOOM = 2;
const ZOOM_STEP = 1.15;

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

window.automationCanvas = function automationCanvas(config) {
    return {
        positions: { ...(config.positions || {}) },
        edges: config.edges || [],
        nodeWidth: config.nodeWidth || 248,
        nodeHeight: config.nodeHeight || 84,
        worldWidth: config.worldWidth || 1200,
        worldHeight: config.worldHeight || 800,

        zoom: 1,
        panX: 40,
        panY: 24,
        stageWidth: 1,
        stageHeight: 1,

        drag: null,
        pan: null,
        hoverSlot: null,

        init() {
            this.measure();

            this._onResize = () => this.measure();
            window.addEventListener('resize', this._onResize);

            // Livewire preserves this Alpine component across morphs, so after
            // every server mutation (add / delete / move / tidy / undo) the
            // authoritative coordinates are re-read from the rendered payload.
            if (window.Livewire && typeof window.Livewire.hook === 'function') {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => queueMicrotask(() => this.syncFromServer()));
                });
            }
        },

        destroy() {
            window.removeEventListener('resize', this._onResize);
        },

        syncFromServer() {
            try {
                this.positions = { ...JSON.parse(this.$el.dataset.positions || '{}') };
            } catch (error) {
                /* keep the current positions if the payload is unreadable */
            }
        },

        measure() {
            const stage = this.$refs.stage;

            if (stage) {
                this.stageWidth = stage.clientWidth || 1;
                this.stageHeight = stage.clientHeight || 1;
            }
        },

        /* ---------------------------------------------------------- *
         *  Geometry
         * ---------------------------------------------------------- */

        get worldStyle() {
            return `width:${this.worldWidth}px;height:${this.worldHeight}px;`
                + `transform:translate(${this.panX}px,${this.panY}px) scale(${this.zoom});`;
        },

        nodeStyle(uid) {
            const point = this.positions[uid] || { x: 0, y: 0 };

            return `left:${point.x}px;top:${point.y}px;`
                + `width:${this.nodeWidth}px;min-height:${this.nodeHeight}px;`;
        },

        anchor(uid) {
            const point = this.positions[uid] || { x: 0, y: 0 };

            return { x: point.x + this.nodeWidth / 2, y: point.y };
        },

        slotPoint(edge) {
            const from = this.anchor(edge.from);
            const top = { x: from.x, y: from.y + this.nodeHeight };

            if (edge.to && this.positions[edge.to]) {
                const to = this.anchor(edge.to);

                return { x: (top.x + to.x) / 2, y: (top.y + to.y) / 2 };
            }

            const spread = (edge.index - (edge.count - 1) / 2) * (this.nodeWidth * 0.6);

            return { x: top.x + spread, y: top.y + 46 };
        },

        slotStyle(edge) {
            const point = this.slotPoint(edge);

            return `left:${point.x}px;top:${point.y}px;`;
        },

        slotKey(edge) {
            return `${edge.from}::${edge.branch}`;
        },

        get wires() {
            const wires = [];

            this.edges.forEach((edge) => {
                if (!edge.to || !this.positions[edge.to] || !this.positions[edge.from]) {
                    return;
                }

                const from = this.anchor(edge.from);
                const to = this.anchor(edge.to);
                const y1 = from.y + this.nodeHeight;
                const bend = Math.max(30, Math.abs(to.y - y1) / 2);

                wires.push({
                    key: `${edge.from}::${edge.branch}::${edge.to}`,
                    d: `M ${from.x} ${y1} C ${from.x} ${y1 + bend}, ${to.x} ${to.y - bend}, ${to.x} ${to.y}`,
                });
            });

            return wires;
        },

        /* ---------------------------------------------------------- *
         *  Zoom & pan
         * ---------------------------------------------------------- */

        toWorld(clientX, clientY) {
            const rect = this.$refs.stage.getBoundingClientRect();

            return {
                x: (clientX - rect.left - this.panX) / this.zoom,
                y: (clientY - rect.top - this.panY) / this.zoom,
            };
        },

        zoomAt(factor, clientX, clientY) {
            const rect = this.$refs.stage.getBoundingClientRect();
            const px = clientX - rect.left;
            const py = clientY - rect.top;
            const world = this.toWorld(clientX, clientY);
            const next = clamp(this.zoom * factor, MIN_ZOOM, MAX_ZOOM);

            this.zoom = next;
            this.panX = px - world.x * next;
            this.panY = py - world.y * next;
        },

        onWheel(event) {
            const rect = this.$refs.stage.getBoundingClientRect();
            const factor = event.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP;

            this.zoomAt(
                factor,
                event.clientX || rect.left + rect.width / 2,
                event.clientY || rect.top + rect.height / 2,
            );
        },

        zoomBy(factor) {
            const rect = this.$refs.stage.getBoundingClientRect();

            this.zoomAt(factor, rect.left + rect.width / 2, rect.top + rect.height / 2);
        },

        zoomIn() {
            this.zoomBy(ZOOM_STEP);
        },

        zoomOut() {
            this.zoomBy(1 / ZOOM_STEP);
        },

        resetView() {
            this.zoom = 1;
            this.panX = 40;
            this.panY = 24;
        },

        fit() {
            this.measure();

            const bounds = this.bounds();
            const zoom = clamp(
                Math.min(
                    (this.stageWidth - 48) / Math.max(1, bounds.width),
                    (this.stageHeight - 48) / Math.max(1, bounds.height),
                ),
                MIN_ZOOM,
                1,
            );

            this.zoom = zoom;
            this.panX = (this.stageWidth - bounds.width * zoom) / 2 - bounds.minX * zoom;
            this.panY = 24 - bounds.minY * zoom;
        },

        bounds() {
            const points = Object.values(this.positions);

            if (points.length === 0) {
                return { minX: 0, minY: 0, width: this.worldWidth, height: this.worldHeight };
            }

            const xs = points.map((p) => p.x);
            const ys = points.map((p) => p.y);
            const minX = Math.min(...xs);
            const minY = Math.min(...ys);

            return {
                minX,
                minY,
                width: Math.max(...xs) - minX + this.nodeWidth,
                height: Math.max(...ys) - minY + this.nodeHeight,
            };
        },

        onStagePointerDown(event) {
            if (event.button !== 0 || this.drag) {
                return;
            }

            this.pan = {
                clientX: event.clientX,
                clientY: event.clientY,
                panX: this.panX,
                panY: this.panY,
            };
        },

        /* ---------------------------------------------------------- *
         *  Node drag & drop
         * ---------------------------------------------------------- */

        startNodeDrag(event, uid) {
            if (event.button !== 0) {
                return;
            }

            const world = this.toWorld(event.clientX, event.clientY);
            const point = this.positions[uid] || { x: 0, y: 0 };

            this.drag = {
                uid,
                offsetX: world.x - point.x,
                offsetY: world.y - point.y,
                originX: point.x,
                originY: point.y,
                moved: false,
            };
            this.hoverSlot = null;
        },

        onPointerMove(event) {
            if (this.drag) {
                const world = this.toWorld(event.clientX, event.clientY);

                this.positions[this.drag.uid] = {
                    x: Math.max(0, Math.round(world.x - this.drag.offsetX)),
                    y: Math.max(0, Math.round(world.y - this.drag.offsetY)),
                };
                this.drag.moved = true;

                return;
            }

            if (this.pan) {
                this.panX = this.pan.panX + (event.clientX - this.pan.clientX);
                this.panY = this.pan.panY + (event.clientY - this.pan.clientY);
            }
        },

        onPointerUp() {
            this.pan = null;

            if (!this.drag) {
                return;
            }

            const drag = this.drag;
            this.drag = null;

            if (!drag.moved) {
                this.hoverSlot = null;

                return;
            }

            if (this.hoverSlot && this.hoverSlot.from !== drag.uid) {
                // Snap back visually; the server answers with the new layout.
                this.positions[drag.uid] = { x: drag.originX, y: drag.originY };
                this.$wire.moveNodeToBranch(drag.uid, this.hoverSlot.from, this.hoverSlot.branch);
                this.hoverSlot = null;

                return;
            }

            this.hoverSlot = null;
            this.$wire.updateNodePositions({ [drag.uid]: this.positions[drag.uid] });
        },

        enterSlot(edge) {
            if (this.drag && this.drag.uid !== edge.from) {
                this.hoverSlot = { from: edge.from, branch: edge.branch };
            }
        },

        leaveSlot(edge) {
            if (this.hoverSlot && this.hoverSlot.from === edge.from && this.hoverSlot.branch === edge.branch) {
                this.hoverSlot = null;
            }
        },

        isTarget(edge) {
            return !!this.hoverSlot
                && this.hoverSlot.from === edge.from
                && this.hoverSlot.branch === edge.branch;
        },

        isDragging(uid) {
            return !!this.drag && this.drag.uid === uid;
        },

        /* ---------------------------------------------------------- *
         *  Minimap
         * ---------------------------------------------------------- */

        get miniScale() {
            return Math.min(
                160 / Math.max(1, this.worldWidth),
                110 / Math.max(1, this.worldHeight),
            );
        },

        miniStyle(uid) {
            const point = this.positions[uid] || { x: 0, y: 0 };
            const scale = this.miniScale;

            return `left:${point.x * scale}px;top:${point.y * scale}px;`
                + `width:${Math.max(3, this.nodeWidth * scale)}px;`
                + `height:${Math.max(2, this.nodeHeight * scale)}px;`;
        },

        get miniViewportStyle() {
            const scale = this.miniScale;
            const left = (-this.panX / this.zoom) * scale;
            const top = (-this.panY / this.zoom) * scale;

            return `left:${left}px;top:${top}px;`
                + `width:${(this.stageWidth / this.zoom) * scale}px;`
                + `height:${(this.stageHeight / this.zoom) * scale}px;`;
        },

        minimapJump(event) {
            const rect = this.$refs.minimap.getBoundingClientRect();
            const scale = this.miniScale;
            const worldX = (event.clientX - rect.left) / scale;
            const worldY = (event.clientY - rect.top) / scale;

            this.panX = this.stageWidth / 2 - worldX * this.zoom;
            this.panY = this.stageHeight / 2 - worldY * this.zoom;
        },

        get nodeUids() {
            return Object.keys(this.positions);
        },
    };
};
