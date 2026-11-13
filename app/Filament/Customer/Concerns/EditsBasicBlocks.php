<?php

declare(strict_types=1);

namespace App\Filament\Customer\Concerns;

use App\Services\Templates\Builders\Basic\BasicBlockRenderer;
use App\Services\Templates\Builders\Basic\BasicBlocks;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The basic builder's editing behaviour (spec §2.5.3 F-2.5.3-001), shared by
 * the template page and the campaign page so both surfaces behave identically
 * — a template and a campaign are the same block document with a different
 * owner (D-59 widened the importers the same way).
 *
 * State lives in a plain public array; every mutation is a Livewire call, so
 * there is no client-side model to drift out of sync and no JS bundle to
 * ship. Drag-and-drop in the view is native HTML5 (draggable + dragstart/drop
 * wired with Alpine, no library) and calls {@see moveBlockTo()}; the up/down
 * buttons call the same method, which keeps the feature usable by keyboard
 * and testable without a browser.
 *
 * The host class must implement {@see persistBlocks()}.
 *
 * @property array<int, array<string, mixed>> $blocks
 */
trait EditsBasicBlocks
{
    /** @var array<int, array<string, mixed>> */
    public array $blocks = [];

    /** Index of the block whose settings panel is open, or null. */
    public ?int $editing = null;

    /** Write $blocks + the rendered HTML back to the owning record. */
    abstract protected function persistBlocks(string $html, string $json): void;

    /** Title used in the rendered document's <title>. */
    abstract protected function documentTitle(): string;

    /**
     * Load stored blocks, falling back to the starter document.
     *
     * Deliberately does NOT write: opening the page must never overwrite
     * content that another builder produced (switching a template from
     * GrapesJS to `basic` and merely looking at it would otherwise destroy
     * the old HTML). The document is persisted on the first real edit.
     */
    protected function loadBlocks(?string $contentJson): void
    {
        $blocks = BasicBlocks::decode($contentJson);

        $this->blocks = $blocks === [] ? BasicBlocks::starter() : $blocks;
    }

    /* ================================================================ *
     *  Mutations
     * ================================================================ */

    public function addBlock(string $type): void
    {
        if (! in_array($type, BasicBlocks::TYPES, true)) {
            return;
        }

        if (count($this->blocks) >= BasicBlocks::MAX_BLOCKS) {
            Notification::make()->warning()
                ->title(__('templates.basic_builder.notifications.max_blocks', ['max' => BasicBlocks::MAX_BLOCKS]))
                ->send();

            return;
        }

        $this->blocks[] = BasicBlocks::make($type);
        $this->editing = count($this->blocks) - 1;

        $this->saveBlocks();
    }

    public function removeBlock(int $index): void
    {
        if (! array_key_exists($index, $this->blocks)) {
            return;
        }

        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);
        $this->editing = null;

        $this->saveBlocks();
    }

    public function duplicateBlock(int $index): void
    {
        if (! array_key_exists($index, $this->blocks) || count($this->blocks) >= BasicBlocks::MAX_BLOCKS) {
            return;
        }

        $copy = $this->blocks[$index];
        $copy['id'] = (string) Str::uuid();

        array_splice($this->blocks, $index + 1, 0, [$copy]);

        $this->saveBlocks();
    }

    /** Reorder: move the block at $from so it sits at position $to. */
    public function moveBlockTo(int $from, int $to): void
    {
        $count = count($this->blocks);

        if ($count < 2 || ! array_key_exists($from, $this->blocks)) {
            return;
        }

        $to = max(0, min($count - 1, $to));

        if ($from === $to) {
            return;
        }

        $moved = array_splice($this->blocks, $from, 1);
        array_splice($this->blocks, $to, 0, $moved);

        if ($this->editing === $from) {
            $this->editing = $to;
        }

        $this->saveBlocks();
    }

    public function moveBlockUp(int $index): void
    {
        $this->moveBlockTo($index, $index - 1);
    }

    public function moveBlockDown(int $index): void
    {
        $this->moveBlockTo($index, $index + 1);
    }

    public function editBlock(?int $index): void
    {
        $this->editing = $index !== null && array_key_exists($index, $this->blocks) ? $index : null;
    }

    /** Called by wire:model.blur on every field in the settings panel. */
    public function updatedBlocks(): void
    {
        $this->saveBlocks();
    }

    /* ================================================================ *
     *  Persistence + preview
     * ================================================================ */

    public function saveBlocks(): void
    {
        $this->blocks = BasicBlocks::normalize($this->blocks);

        $this->persistBlocks($this->renderHtml(), BasicBlocks::encode($this->blocks));
    }

    public function renderHtml(): string
    {
        return app(BasicBlockRenderer::class)->render($this->blocks, $this->documentTitle());
    }

    /** @return array<string, string> */
    public function blockTypeOptions(): array
    {
        return BasicBlocks::typeOptions();
    }
}
