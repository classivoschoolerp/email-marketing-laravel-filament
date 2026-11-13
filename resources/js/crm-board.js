/**
 * F-2.13-003 — CRM pipeline board drag & drop (spec §2.13).
 *
 * Hand-rolled Alpine component, zero new npm dependencies — Pointer Events
 * and CSS transforms only, exactly like the WP-27C automation canvas. It is
 * registered as a plain global factory (not through `alpine:init`) because
 * Filament boots Alpine on DOMContentLoaded, after every deferred module in
 * the page body has run, so `x-data="crmBoard(...)"` always resolves.
 *
 * The browser owns NOTHING durable. On drop it calls
 *   $wire.moveDeal(dealUid, stageUid, orderedUidsOfTargetColumn)
 * and the server (App\Services\Crm\PipelineBoard) decides whether the move is
 * legal, rewrites the dense 0..n-1 positions and re-renders. The optimistic
 * local reorder below is purely so the card does not visibly snap back for
 * the length of one round trip; the server's answer always wins.
 */

import '../css/crm-board.css';

window.crmBoard = function crmBoard(config) {
    return {
        /** stageUid => [dealUid, ...], mirrored from the rendered payload. */
        columns: { ...(config.columns || {}) },

        drag: null,
        hoverStage: null,
        pointer: { x: 0, y: 0 },

        init() {
            // Livewire preserves this component across morphs, so after every
            // server mutation the authoritative order is re-read from the DOM.
            if (window.Livewire && typeof window.Livewire.hook === 'function') {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => queueMicrotask(() => this.syncFromServer()));
                });
            }
        },

        syncFromServer() {
            try {
                this.columns = { ...JSON.parse(this.$el.dataset.columns || '{}') };
            } catch (error) {
                /* keep the current order if the payload is unreadable */
            }
        },

        /* ------------------------------------------------------------ *
         *  Drag
         * ------------------------------------------------------------ */

        startDrag(event, dealUid, stageUid) {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();

            this.drag = { dealUid, fromStage: stageUid, moved: false };
            this.hoverStage = stageUid;
            this.pointer = { x: event.clientX, y: event.clientY };
        },

        onPointerMove(event) {
            if (!this.drag) {
                return;
            }

            this.drag.moved = true;
            this.pointer = { x: event.clientX, y: event.clientY };

            const column = this.columnUnder(event.clientX, event.clientY);

            if (column) {
                this.hoverStage = column;
            }
        },

        onPointerUp(event) {
            if (!this.drag) {
                return;
            }

            const drag = this.drag;
            const target = this.columnUnder(event.clientX, event.clientY) || this.hoverStage;

            this.drag = null;
            this.hoverStage = null;

            if (!drag.moved || !target) {
                return;
            }

            const index = this.insertionIndex(target, event.clientY, drag.dealUid);
            const ordered = this.reorder(drag.fromStage, target, drag.dealUid, index);

            this.$wire.moveDeal(drag.dealUid, target, ordered);
        },

        /** Which column is under the pointer? */
        columnUnder(clientX, clientY) {
            const columns = this.$el.querySelectorAll('[data-stage-uid]');

            for (const column of columns) {
                const rect = column.getBoundingClientRect();

                if (clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom) {
                    return column.dataset.stageUid;
                }
            }

            return null;
        },

        /**
         * Where in the target column does the card land? The first card whose
         * vertical midpoint sits below the pointer.
         */
        insertionIndex(stageUid, clientY, movedUid) {
            const cards = this.$el.querySelectorAll(`[data-stage-uid="${stageUid}"] [data-deal-uid]`);
            let index = 0;

            for (const card of cards) {
                if (card.dataset.dealUid === movedUid) {
                    continue;
                }

                const rect = card.getBoundingClientRect();

                if (clientY < rect.top + rect.height / 2) {
                    return index;
                }

                index += 1;
            }

            return index;
        },

        /** Optimistic local reorder; returns the target column's new order. */
        reorder(fromStage, toStage, dealUid, index) {
            const from = (this.columns[fromStage] || []).filter((uid) => uid !== dealUid);
            const to = fromStage === toStage ? from : (this.columns[toStage] || []).filter((uid) => uid !== dealUid);

            to.splice(Math.max(0, Math.min(index, to.length)), 0, dealUid);

            this.columns[fromStage] = fromStage === toStage ? to : from;
            this.columns[toStage] = to;

            return [...to];
        },

        isDragging(dealUid) {
            return !!this.drag && this.drag.dealUid === dealUid;
        },

        isTarget(stageUid) {
            return !!this.drag && this.hoverStage === stageUid;
        },

        cardsIn(stageUid) {
            return this.columns[stageUid] || [];
        },
    };
};
