<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\AutomationResource\Pages;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Filament\Customer\Resources\AutomationResource;
use App\Filament\Customer\Resources\AutomationResource\Support\NodeForms;
use App\Jobs\Automations\EnrollSubscribersJob;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Automations\AutomationStats;
use App\Services\Automations\CanvasLayout;
use App\Services\Automations\Graph;
use App\Services\Automations\GraphPublisher;
use App\Services\Automations\SimulateRunner;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §2.4.2 visual workflow builder. The flow is a free-positioned node canvas:
 * every node carries integer x/y in the canvas JSON (CanvasLayout), edges are
 * drawn between them and the browser layer (resources/js/automation-canvas.js,
 * hand-rolled Alpine — no new packages) adds pointer drag, wheel/button zoom,
 * drag-to-pan and a minimap.
 *
 * Shipped here (F-2.4.2-001):
 *  - drag & drop      updateNodePositions() persists coordinates;
 *                     moveNodeToBranch() re-parents a node onto another edge
 *  - auto-layout      autoLayout() → CanvasLayout::applyTidy() tidy tree
 *  - undo / redo      undo()/redo() over a bounded stack of canvas snapshots
 *                     held in Livewire component state (it round-trips through
 *                     the browser — the "client-side stack") and written back
 *                     with the same save path as every other mutation
 *  - zoom/pan/minimap are pure browser state (documented as hand-verified).
 *
 * Plus the pre-existing P1 behaviour: add-node-between / edit / delete on every
 * edge, save draft + publish + versioning (F-2.4.2-002), test-simulate
 * (F-2.4.2-003) and live per-node contact counts (F-2.4.2-004).
 */
class BuildAutomation extends Page
{
    use InteractsWithRecord;

    /** Snapshots kept for undo/redo (bounded — the stack rides the wire). */
    public const HISTORY_LIMIT = 30;

    protected static string $resource = AutomationResource::class;

    protected static string $view = 'filament.customer.resources.automation-resource.pages.build-automation';

    /** @var array{nodes?: list<array<string, mixed>>} */
    public array $canvas = [];

    /** @var list<array<string, mixed>> */
    public array $simulationSteps = [];

    /**
     * Undo/redo stack of whole canvas snapshots, oldest first.
     *
     * @var list<array<string, mixed>>
     */
    public array $history = [];

    /** Pointer into $history; everything after it is redoable. */
    public int $historyIndex = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->getRecord()) ?? false, 403);

        $this->canvas = (array) ($this->getRecord()->canvas ?? ['nodes' => []]);

        // Canvases authored before F-2.4.2-001 have no coordinates: lay them
        // out once, persist, and every later edit keeps the operator's own
        // positions.
        [$nodes, $changed] = app(CanvasLayout::class)->ensurePositions($this->nodes());

        if ($changed) {
            $this->canvas['nodes'] = $nodes;
            $this->persistDraft();
        }

        $this->history = [$this->canvas];
        $this->historyIndex = 0;
    }

    public function getRecord(): Automation
    {
        /** @var Automation $record */
        $record = $this->record;

        return $record;
    }

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return $record->isPublished()
            ? __('automations.builder.live_hint').' ('.__('automations.fields.version').' '.$record->version.')'
            : __('automations.builder.draft_hint');
    }

    /* ------------------------------------------------------------------ *
     *  Canvas helpers (blade-facing).
     * ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return array_values((array) ($this->canvas['nodes'] ?? []));
    }

    /** @return array<string, array<string, mixed>> */
    public function nodesByUid(): array
    {
        $byUid = [];

        foreach ($this->nodes() as $node) {
            $byUid[(string) $node['uid']] = $node;
        }

        return $byUid;
    }

    /**
     * Render-ready node cards: enum type, human summary and the persisted
     * canvas coordinates (F-2.4.2-001).
     *
     * @return list<array{uid: string, type: AutomationNodeType, label: ?string, summary: string, x: int, y: int}>
     */
    public function canvasNodes(): array
    {
        $cards = [];

        foreach ($this->nodes() as $node) {
            $type = AutomationNodeType::tryFrom((string) ($node['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $cards[] = [
                'uid' => (string) $node['uid'],
                'type' => $type,
                'label' => $node['label'] ?? null,
                'summary' => NodeForms::summary($node),
                'x' => (int) ($node['x'] ?? 0),
                'y' => (int) ($node['y'] ?? 0),
            ];
        }

        return $cards;
    }

    /**
     * One row per branch slot (connected or free) — free slots are the "+"
     * add buttons and the drop targets a dragged node can be released onto.
     *
     * @return list<array{from: string, branch: string, to: ?string}>
     */
    public function canvasEdges(): array
    {
        return app(CanvasLayout::class)->edges($this->nodes());
    }

    /** Bounding box of the laid-out graph, used to size the scroll world. */
    public function canvasExtent(): array
    {
        $maxX = 0;
        $maxY = 0;

        foreach ($this->nodes() as $node) {
            $maxX = max($maxX, (int) ($node['x'] ?? 0));
            $maxY = max($maxY, (int) ($node['y'] ?? 0));
        }

        return [
            'width' => $maxX + CanvasLayout::NODE_WIDTH + CanvasLayout::X_UNIT * 2,
            'height' => $maxY + CanvasLayout::NODE_HEIGHT + CanvasLayout::Y_STEP,
            'node_width' => CanvasLayout::NODE_WIDTH,
            'node_height' => CanvasLayout::NODE_HEIGHT,
        ];
    }

    public function branchLabel(string $branch): string
    {
        return match (true) {
            in_array($branch, ['next', 'yes', 'no', 'event', 'timeout', 'default'], true) => __('automations.builder.branch.'.$branch),
            str_starts_with($branch, 'case:') => __('automations.fields.case_value').' '.(((int) substr($branch, 5)) + 1),
            default => mb_strtoupper($branch),
        };
    }

    /* ------------------------------------------------------------------ *
     *  Canvas mutations driven by the browser layer (F-2.4.2-001).
     * ------------------------------------------------------------------ */

    /**
     * Commit a pointer drag: uid => {x, y} in canvas pixels. Unknown uids are
     * ignored, coordinates are clamped to a sane canvas and rounded to ints
     * (the wire carries whatever the browser measured).
     *
     * @param  array<string, mixed>  $positions
     */
    public function updateNodePositions(array $positions): void
    {
        $byUid = $this->nodesByUid();
        $nodes = $this->nodes();
        $changed = false;

        foreach ($positions as $uid => $point) {
            $uid = (string) $uid;

            if (! isset($byUid[$uid]) || ! is_array($point)) {
                continue;
            }

            $x = max(0, min(100_000, (int) round((float) ($point['x'] ?? 0))));
            $y = max(0, min(100_000, (int) round((float) ($point['y'] ?? 0))));

            foreach ($nodes as $index => $node) {
                if ((string) $node['uid'] === $uid) {
                    $nodes[$index]['x'] = $x;
                    $nodes[$index]['y'] = $y;
                    $changed = true;
                }
            }
        }

        if (! $changed) {
            return;
        }

        $this->canvas['nodes'] = $nodes;
        $this->commit();
    }

    /**
     * Drop a node onto another edge: unhook it from its current parent (the
     * parent inherits the node's primary child, exactly like delete splices)
     * and hook it under $parentUid/$branch, pushing whatever hung there onto
     * the moved node's own primary branch.
     *
     * Refused when it would move the trigger, self-attach, or close a loop
     * (the jump node is the supported way to loop).
     */
    public function moveNodeToBranch(string $uid, string $parentUid, string $branch): void
    {
        $byUid = $this->nodesByUid();

        if ($uid === $parentUid || ! isset($byUid[$uid], $byUid[$parentUid])) {
            return;
        }

        $type = AutomationNodeType::tryFrom((string) $byUid[$uid]['type']);
        $parentType = AutomationNodeType::tryFrom((string) $byUid[$parentUid]['type']);

        if ($type === null || $parentType === null || $type->isTrigger()) {
            return;
        }

        if (! in_array($branch, AutomationNode::branchKeysFor($parentType, (array) ($byUid[$parentUid]['config'] ?? [])), true)) {
            return;
        }

        $primaryBranch = AutomationNode::branchKeysFor($type, (array) ($byUid[$uid]['config'] ?? []))[0] ?? null;
        $primaryChild = '';

        foreach ((array) ($byUid[$uid]['edges'] ?? []) as $childUid) {
            if ((string) ($childUid ?? '') !== '') {
                $primaryChild = (string) $childUid;
                break;
            }
        }

        // 1. Detach: every edge into the node now points at its primary child.
        $nodes = [];

        foreach ($this->nodes() as $node) {
            if ((string) $node['uid'] === $uid) {
                if ($primaryBranch !== null) {
                    unset($node['edges'][$primaryBranch]);
                }

                $nodes[] = $node;

                continue;
            }

            foreach ((array) ($node['edges'] ?? []) as $edgeBranch => $childUid) {
                if ((string) ($childUid ?? '') === $uid) {
                    if ($primaryChild !== '') {
                        $node['edges'][$edgeBranch] = $primaryChild;
                    } else {
                        unset($node['edges'][$edgeBranch]);
                    }
                }
            }

            $nodes[] = $node;
        }

        // 2. Loop guard: after the detach the new parent must not already be
        //    downstream of the node we are about to hang it under.
        if ($this->reaches($nodes, $uid, $parentUid)) {
            Notification::make()->danger()->title(__('automations.notifications.move_would_loop'))->send();

            return;
        }

        // 3. Attach under parent/branch, pushing the old occupant down.
        $existing = '';

        foreach ($nodes as $index => $node) {
            if ((string) $node['uid'] === $parentUid) {
                $existing = (string) (($node['edges'][$branch] ?? '') ?? '');
                $nodes[$index]['edges'][$branch] = $uid;
            }
        }

        if ($existing !== '' && $existing !== $uid && $primaryBranch !== null) {
            foreach ($nodes as $index => $node) {
                if ((string) $node['uid'] === $uid) {
                    $nodes[$index]['edges'][$primaryBranch] = $existing;
                }
            }
        }

        $this->canvas['nodes'] = $this->pruneUnreachable($nodes);
        $this->commit();

        Notification::make()->success()->title(__('automations.notifications.node_moved'))->send();
    }

    /** F-2.4.2-001 auto-layout: rewrite every coordinate as a tidy tree. */
    public function autoLayout(): void
    {
        $this->canvas['nodes'] = app(CanvasLayout::class)->applyTidy($this->nodes());
        $this->commit();

        Notification::make()->success()->title(__('automations.notifications.auto_laid_out'))->send();
    }

    public function canUndo(): bool
    {
        return $this->historyIndex > 0;
    }

    public function canRedo(): bool
    {
        return $this->historyIndex < count($this->history) - 1;
    }

    /** Step back one canvas snapshot and save it (F-2.4.2-001). */
    public function undo(): void
    {
        if (! $this->canUndo()) {
            return;
        }

        $this->historyIndex--;
        $this->canvas = $this->history[$this->historyIndex];
        $this->persistDraft();
    }

    /** Step forward again through snapshots undone in this session. */
    public function redo(): void
    {
        if (! $this->canRedo()) {
            return;
        }

        $this->historyIndex++;
        $this->canvas = $this->history[$this->historyIndex];
        $this->persistDraft();
    }

    /** Live counts per node uid (F-2.4.2-004). @return array<string, array{active: int, reached: int}> */
    public function liveCounts(): array
    {
        $record = $this->getRecord();

        if (! $record->isPublished()) {
            return [];
        }

        $nodes = $record->nodes()->get();
        $active = app(AutomationStats::class)->activeByNode($record);

        $counts = [];

        foreach ($nodes as $node) {
            $counts[(string) $node->uid] = [
                'active' => (int) ($active[$node->id] ?? 0),
                'reached' => (int) $node->entered_count,
            ];
        }

        return $counts;
    }

    /** Inbound hook URL shown on webhook-trigger cards (F-2.4.2-014). */
    public function webhookUrl(): ?string
    {
        $record = $this->getRecord();

        foreach ($this->nodes() as $node) {
            if ((string) $node['type'] === AutomationNodeType::TriggerWebhook->value) {
                $token = (string) data_get($node, 'config.token', '');

                return $token !== ''
                    ? route('automations.hook', ['uid' => $record->uid, 'token' => $token])
                    : null;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ *
     *  Header actions.
     * ------------------------------------------------------------------ */

    protected function getHeaderActions(): array
    {
        return [
            $this->statusAction(),
            $this->simulateAction(),
            $this->enrollAction(),
            $this->versionsAction(),
            Action::make('saveDraft')
                ->label(__('automations.actions.save_draft'))
                ->color('gray')
                ->action(fn () => $this->persistDraft(notify: true)),
            Action::make('publish')
                ->label(__('automations.actions.publish'))
                ->icon('heroicon-m-rocket-launch')
                ->action(function (): void {
                    $this->publish();
                }),
        ];
    }

    private function statusAction(): Action
    {
        $record = $this->getRecord();

        if ($record->status === AutomationStatus::Active) {
            return Action::make('pause')
                ->label(__('automations.actions.pause'))
                ->icon('heroicon-m-pause')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->getRecord()->forceFill(['status' => AutomationStatus::Paused])->save();

                    Notification::make()->success()->title(__('automations.notifications.paused'))->send();
                });
        }

        return Action::make('activate')
            ->label(__('automations.actions.activate'))
            ->icon('heroicon-m-play')
            ->color('success')
            ->action(function (): void {
                $record = $this->getRecord();

                if (! $record->isPublished()) {
                    if (! $this->publish()) {
                        return;
                    }

                    $record->refresh();
                }

                $record->forceFill(['status' => AutomationStatus::Active])->save();

                Notification::make()->success()->title(__('automations.notifications.activated'))->send();
            });
    }

    private function simulateAction(): Action
    {
        return Action::make('simulate')
            ->label(__('automations.actions.simulate'))
            ->icon('heroicon-m-beaker')
            ->color('gray')
            ->form([
                Forms\Components\TextInput::make('email')
                    ->label(__('automations.fields.value'))
                    ->email()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $subscriber = Subscriber::query()
                    ->where('email', mb_strtolower(trim((string) $data['email'])))
                    ->orderBy('id')
                    ->first();

                if ($subscriber === null) {
                    Notification::make()->danger()->title(__('automations.notifications.simulate_failed'))->send();

                    return;
                }

                $this->persistDraft();

                $automation = $this->getRecord();
                $graph = Graph::fromCanvas($this->nodes());

                $this->simulationSteps = app(SimulateRunner::class)
                    ->simulateGraph($automation, $graph, $subscriber);
            });
    }

    private function enrollAction(): Action
    {
        return Action::make('enroll')
            ->label(__('automations.actions.enroll'))
            ->icon('heroicon-m-user-plus')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->isPublished())
            ->form([
                Forms\Components\Radio::make('source')
                    ->label(__('automations.fields.enroll_source'))
                    ->options([
                        'list' => __('automations.options.enroll_list'),
                        'segment' => __('automations.options.enroll_segment'),
                        'emails' => __('automations.options.enroll_emails'),
                    ])
                    ->default('list')->live()->required(),

                Forms\Components\Select::make('list_id')
                    ->label(__('automations.fields.list'))
                    ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (Forms\Get $get): bool => $get('source') === 'list')
                    ->required(fn (Forms\Get $get): bool => $get('source') === 'list'),

                Forms\Components\Select::make('segment_id')
                    ->label(__('automations.fields.segment'))
                    ->options(fn (): array => NodeForms::segmentOptions())
                    ->visible(fn (Forms\Get $get): bool => $get('source') === 'segment')
                    ->required(fn (Forms\Get $get): bool => $get('source') === 'segment'),

                Forms\Components\Textarea::make('emails')
                    ->label(__('automations.fields.emails'))
                    ->rows(4)
                    ->visible(fn (Forms\Get $get): bool => $get('source') === 'emails')
                    ->required(fn (Forms\Get $get): bool => $get('source') === 'emails'),
            ])
            ->action(function (array $data): void {
                $record = $this->getRecord();

                if ($record->status !== AutomationStatus::Active) {
                    Notification::make()->danger()->title(__('automations.notifications.cannot_activate_unpublished'))->send();

                    return;
                }

                $listId = null;
                $segmentId = null;
                $subscriberIds = [];

                if ($data['source'] === 'list') {
                    $listId = (int) $data['list_id'];
                    abort_unless(EmailList::query()->whereKey($listId)->exists(), 403);
                } elseif ($data['source'] === 'segment') {
                    $segmentId = (int) $data['segment_id'];
                    abort_unless(ListSegment::query()->whereKey($segmentId)->exists(), 403);
                } else {
                    $emails = Collection::make(preg_split('/[\s,;]+/', (string) $data['emails']) ?: [])
                        ->map(fn (string $email): string => mb_strtolower(trim($email)))
                        ->filter(fn (string $email): bool => $email !== '')
                        ->unique();

                    $subscriberIds = Subscriber::query()
                        ->whereIn('email', $emails)
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();
                }

                EnrollSubscribersJob::dispatch(
                    (int) $record->id,
                    $listId,
                    $segmentId,
                    $subscriberIds,
                    (string) Str::uuid(),
                );

                Notification::make()->success()->title(__('automations.notifications.enroll_queued'))->send();
            });
    }

    private function versionsAction(): Action
    {
        return Action::make('versions')
            ->label(__('automations.actions.versions'))
            ->icon('heroicon-m-clock')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->versions()->exists())
            ->form([
                Forms\Components\Select::make('version')
                    ->label(__('automations.fields.version'))
                    ->options(fn (): array => $this->getRecord()->versions()
                        ->get()
                        ->mapWithKeys(fn ($version): array => [
                            (int) $version->version => __('automations.fields.version').' '.$version->version
                                .' — '.$version->published_at->diffForHumans(),
                        ])->all())
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('automations.actions.restore_version'))
            ->action(function (array $data): void {
                $snapshot = $this->getRecord()->versions()
                    ->where('version', (int) $data['version'])
                    ->first();

                if ($snapshot === null) {
                    return;
                }

                $this->canvas = (array) $snapshot->canvas;
                [$this->canvas['nodes']] = app(CanvasLayout::class)->ensurePositions($this->nodes());
                $this->commit();

                Notification::make()->success()
                    ->title(__('automations.notifications.version_restored', ['version' => $snapshot->version]))
                    ->send();
            });
    }

    /* ------------------------------------------------------------------ *
     *  Node CRUD actions (mounted from the blade).
     * ------------------------------------------------------------------ */

    public function addNodeAction(): Action
    {
        return Action::make('addNode')
            ->label(__('automations.actions.add_node'))
            ->form(fn (array $arguments): array => [
                Forms\Components\Select::make('type')
                    ->label(__('automations.fields.node_type'))
                    ->options(NodeForms::typeOptions())
                    ->live()
                    ->required(),
                ...NodeForms::configSchema(fn (): array => $this->nodeLabels()),
            ])
            ->action(function (array $data, array $arguments): void {
                $parentUid = (string) ($arguments['parent'] ?? '');
                $branch = (string) ($arguments['branch'] ?? 'next');

                $type = AutomationNodeType::tryFrom((string) ($data['type'] ?? ''));

                if ($type === null || $type->isTrigger()) {
                    return;
                }

                $nodes = $this->nodes();
                $byUid = $this->nodesByUid();

                if (! isset($byUid[$parentUid])) {
                    return;
                }

                $newUid = 'n'.Str::lower(Str::random(7));
                $config = $this->cleanConfig($type, (array) ($data['config'] ?? []));

                $existingChild = (string) (($byUid[$parentUid]['edges'][$branch] ?? '') ?? '');
                $edges = [];

                $primary = AutomationNode::branchKeysFor($type, $config)[0] ?? null;

                if ($existingChild !== '' && $primary !== null) {
                    $edges[$primary] = $existingChild;
                }

                foreach ($nodes as $index => $node) {
                    if ((string) $node['uid'] === $parentUid) {
                        $nodes[$index]['edges'][$branch] = $newUid;
                    }
                }

                $slot = app(CanvasLayout::class)->slotUnder($nodes, $parentUid);

                $nodes[] = [
                    'uid' => $newUid,
                    'type' => $type->value,
                    'label' => ($data['label'] ?? '') !== '' ? (string) $data['label'] : null,
                    'config' => $config,
                    'edges' => $edges,
                    'x' => $slot['x'],
                    'y' => $slot['y'],
                ];

                $this->canvas['nodes'] = $nodes;
                $this->commit();

                Notification::make()->success()->title(__('automations.notifications.node_added'))->send();
            });
    }

    public function editNodeAction(): Action
    {
        return Action::make('editNode')
            ->label(__('automations.actions.edit_node'))
            ->fillForm(function (array $arguments): array {
                $node = $this->nodesByUid()[(string) ($arguments['uid'] ?? '')] ?? null;

                return [
                    'type' => (string) ($node['type'] ?? ''),
                    'label' => $node['label'] ?? null,
                    'config' => (array) ($node['config'] ?? []),
                ];
            })
            ->form(fn (): array => [
                Forms\Components\Select::make('type')
                    ->label(__('automations.fields.node_type'))
                    ->options(NodeForms::typeOptions(includeTriggers: true))
                    ->disabled()
                    ->dehydrated(),
                ...NodeForms::configSchema(fn (): array => $this->nodeLabels()),
            ])
            ->action(function (array $data, array $arguments): void {
                $uid = (string) ($arguments['uid'] ?? '');
                $nodes = $this->nodes();

                foreach ($nodes as $index => $node) {
                    if ((string) $node['uid'] !== $uid) {
                        continue;
                    }

                    $type = AutomationNodeType::from((string) $node['type']);

                    $nodes[$index]['label'] = ($data['label'] ?? '') !== '' ? (string) $data['label'] : null;
                    $nodes[$index]['config'] = $this->cleanConfig($type, (array) ($data['config'] ?? []));
                }

                $this->canvas['nodes'] = $nodes;
                $this->commit();

                Notification::make()->success()->title(__('automations.notifications.node_updated'))->send();
            });
    }

    public function deleteNodeAction(): Action
    {
        return Action::make('deleteNode')
            ->label(__('automations.actions.delete_node'))
            ->requiresConfirmation()
            ->color('danger')
            ->action(function (array $arguments): void {
                $uid = (string) ($arguments['uid'] ?? '');
                $byUid = $this->nodesByUid();
                $target = $byUid[$uid] ?? null;

                if ($target === null) {
                    return;
                }

                $type = AutomationNodeType::tryFrom((string) $target['type']);

                if ($type !== null && $type->isTrigger()) {
                    return; // the trigger can be edited, never removed
                }

                // Splice: the parent edge takes over the node's primary child.
                $primaryChild = '';

                foreach ((array) ($target['edges'] ?? []) as $childUid) {
                    if (($childUid ?? '') !== '') {
                        $primaryChild = (string) $childUid;
                        break;
                    }
                }

                $nodes = [];

                foreach ($this->nodes() as $node) {
                    if ((string) $node['uid'] === $uid) {
                        continue;
                    }

                    foreach ((array) ($node['edges'] ?? []) as $branch => $childUid) {
                        if ((string) ($childUid ?? '') === $uid) {
                            if ($primaryChild !== '') {
                                $node['edges'][$branch] = $primaryChild;
                            } else {
                                unset($node['edges'][$branch]);
                            }
                        }
                    }

                    $nodes[] = $node;
                }

                $this->canvas['nodes'] = $this->pruneUnreachable($nodes);
                $this->commit();

                Notification::make()->success()->title(__('automations.notifications.node_removed'))->send();
            });
    }

    public function publish(): bool
    {
        $this->persistDraft();

        try {
            app(GraphPublisher::class)->publish($this->getRecord());
        } catch (ValidationException $exception) {
            Notification::make()->danger()
                ->title(__('automations.notifications.publish_failed'))
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->persistent()
                ->send();

            return false;
        }

        $this->record = $this->getRecord()->refresh();

        Notification::make()->success()
            ->title(__('automations.notifications.published', ['version' => $this->getRecord()->version]))
            ->send();

        return true;
    }

    /* ------------------------------------------------------------------ *
     *  Internals.
     * ------------------------------------------------------------------ */

    private function persistDraft(bool $notify = false): void
    {
        $this->getRecord()->forceFill(['canvas' => $this->canvas])->save();

        if ($notify) {
            Notification::make()->success()->title(__('automations.notifications.draft_saved'))->send();
        }
    }

    /**
     * Save + push an undo snapshot. Anything after the pointer is dropped —
     * a new edit after an undo forks the timeline, the usual editor rule.
     */
    private function commit(): void
    {
        $this->persistDraft();

        $this->history = array_slice($this->history, 0, $this->historyIndex + 1);
        $this->history[] = $this->canvas;

        if (count($this->history) > self::HISTORY_LIMIT) {
            $this->history = array_slice($this->history, -self::HISTORY_LIMIT);
        }

        $this->history = array_values($this->history);
        $this->historyIndex = count($this->history) - 1;
    }

    /**
     * Is $to reachable from $from by following edges (cycle-safe)?
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function reaches(array $nodes, string $from, string $to): bool
    {
        $byUid = [];

        foreach ($nodes as $node) {
            $byUid[(string) $node['uid']] = $node;
        }

        if (! isset($byUid[$from])) {
            return false;
        }

        $seen = [$from => true];
        $queue = [$from];

        while ($queue !== []) {
            $uid = array_shift($queue);

            foreach ((array) ($byUid[$uid]['edges'] ?? []) as $childUid) {
                $childUid = (string) ($childUid ?? '');

                if ($childUid === $to) {
                    return true;
                }

                if ($childUid !== '' && isset($byUid[$childUid]) && ! isset($seen[$childUid])) {
                    $seen[$childUid] = true;
                    $queue[] = $childUid;
                }
            }
        }

        return false;
    }

    /** @return array<string, string> uid => label (jump target options) */
    private function nodeLabels(): array
    {
        $labels = [];

        foreach ($this->nodes() as $node) {
            $type = AutomationNodeType::tryFrom((string) $node['type']);
            $labels[(string) $node['uid']] = ($node['label'] ?? null)
                ?: ($type?->label() ?? (string) $node['type']);
        }

        return $labels;
    }

    /**
     * Keep only the keys the node type actually uses and normalize enum-ish
     * values (several form components share state paths across types).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function cleanConfig(AutomationNodeType $type, array $config): array
    {
        $keep = match ($type) {
            AutomationNodeType::TriggerSubscribed => ['list_id', 'segment_id'],
            AutomationNodeType::TriggerUnsubscribed,
            AutomationNodeType::TriggerFormSubmitted => ['list_id'],
            AutomationNodeType::TriggerTagAdded,
            AutomationNodeType::TriggerTagRemoved,
            AutomationNodeType::ActionAddTag,
            AutomationNodeType::ActionRemoveTag => ['tag'],
            AutomationNodeType::TriggerFieldChanged => ['list_field_id', 'operator', 'value'],
            AutomationNodeType::TriggerCampaignOpened => ['campaign_id'],
            AutomationNodeType::TriggerLinkClicked => ['campaign_id', 'url'],
            AutomationNodeType::TriggerCampaignNotOpened => ['campaign_id', 'days'],
            AutomationNodeType::TriggerSurveyCompleted, AutomationNodeType::TriggerManual => [],
            AutomationNodeType::TriggerDate => ['list_field_id', 'offset_days', 'annual'],
            AutomationNodeType::TriggerWebhook => ['list_id', 'token'],
            AutomationNodeType::TriggerSegmentEntered,
            AutomationNodeType::TriggerSegmentExited => ['segment_id'],
            // WP-44 §2.13 CRM (F-2.4.2-017).
            AutomationNodeType::TriggerDealStageChanged => ['pipeline_id', 'stage_id'],
            AutomationNodeType::TriggerLeadScore => ['threshold', 'direction'],
            AutomationNodeType::ActionSendEmail => ['mode', 'template_id', 'subject', 'content_html', 'from_name', 'from_email', 'reply_to', 'track'],
            AutomationNodeType::ActionNotifyTeam => ['recipients', 'subject', 'body'],
            AutomationNodeType::ActionUpdateField => ['list_field_id', 'value'],
            AutomationNodeType::ActionList => ['op', 'list_id'],
            AutomationNodeType::ActionSuppress => ['mode', 'suppression_list_id'],
            AutomationNodeType::ActionCrossAutomation => ['op', 'automation_id'],
            AutomationNodeType::ActionWebhook => ['url', 'payload'],
            // WP-32 §2.10 F-2.10-012 — multichannel journey node.
            AutomationNodeType::ActionSms => ['body', 'sender_id', 'sms_gateway_id', 'phone_field_id', 'shorten_links'],
            // WP-46 §2.10 — F-2.4.2-022 / F-2.4.2-023.
            AutomationNodeType::ActionWhatsApp => ['whatsapp_template_id', 'variables', 'header_media_url', 'whatsapp_gateway_id', 'phone_field_id'],
            AutomationNodeType::ActionWebPush => ['title', 'body', 'url', 'icon_url'],
            // WP-44 §2.13 CRM (F-2.4.2-031/032/033).
            AutomationNodeType::ActionCrm => ['op', 'title', 'description', 'task_type', 'due_days', 'stage_id', 'value_minor'],
            AutomationNodeType::ActionLeadScore => ['op', 'points'],
            AutomationNodeType::ActionAssignOwner => ['mode', 'sub_user_id'],
            AutomationNodeType::LogicDelay => ['mode', 'value', 'unit', 'date', 'weekday', 'time', 'event', 'timeout_value', 'timeout_unit'],
            AutomationNodeType::LogicWaitEvent => ['event', 'tag', 'campaign_id', 'url', 'timeout_value', 'timeout_unit'],
            AutomationNodeType::LogicIfElse => ['match', 'conditions'],
            AutomationNodeType::LogicSplit => ['source', 'list_field_id', 'cases'],
            AutomationNodeType::LogicPercentage => ['variants'],
            AutomationNodeType::LogicGoal => ['match', 'conditions', 'conversion_value', 'wait', 'continuous'],
            AutomationNodeType::LogicExit => [],
            AutomationNodeType::LogicJump => ['target', 'target_node_uid', 'target_automation_id'],
            AutomationNodeType::LogicFrequencyGuard => ['quiet_enabled', 'quiet_start', 'quiet_end', 'cap_enabled', 'cap_max', 'cap_hours'],
        };

        $clean = array_intersect_key($config, array_flip($keep));

        // Shared 'mode' state path — normalize per type.
        $modes = match ($type) {
            AutomationNodeType::ActionSendEmail => ['inline', 'template'],
            AutomationNodeType::ActionSuppress => ['blacklist', 'suppression'],
            AutomationNodeType::LogicDelay => ['duration', 'until_date', 'until_time', 'until_event'],
            // WP-44: the owner node shares the 'mode' state path too.
            AutomationNodeType::ActionAssignOwner => ['round_robin', 'specific'],
            default => null,
        };

        if ($modes !== null && ! in_array($clean['mode'] ?? null, $modes, true)) {
            $clean['mode'] = $modes[0];
        }

        $ops = match ($type) {
            AutomationNodeType::ActionList => ['add', 'remove', 'move'],
            AutomationNodeType::ActionCrossAutomation => ['subscribe', 'unsubscribe'],
            // WP-44 §2.13 CRM.
            AutomationNodeType::ActionCrm => ['create_task', 'create_deal', 'update_deal_stage'],
            AutomationNodeType::ActionLeadScore => ['add', 'set'],
            default => null,
        };

        if ($ops !== null && ! in_array($clean['op'] ?? null, $ops, true)) {
            $clean['op'] = $ops[0];
        }

        if ($type === AutomationNodeType::TriggerWebhook && (($clean['token'] ?? '') === '')) {
            $clean['token'] = Str::random(32);
        }

        return $clean;
    }

    /**
     * Drop nodes no longer reachable from the trigger after a deletion.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function pruneUnreachable(array $nodes): array
    {
        $byUid = [];
        $triggerUid = null;

        foreach ($nodes as $node) {
            $byUid[(string) $node['uid']] = $node;
            $type = AutomationNodeType::tryFrom((string) $node['type']);

            if ($type !== null && $type->isTrigger()) {
                $triggerUid = (string) $node['uid'];
            }
        }

        if ($triggerUid === null) {
            return $nodes;
        }

        $seen = [$triggerUid => true];
        $queue = [$triggerUid];

        while ($queue !== []) {
            $uid = array_shift($queue);
            $node = $byUid[$uid];

            $targets = array_values(array_filter(array_map(
                fn ($target): string => (string) ($target ?? ''),
                (array) ($node['edges'] ?? []),
            ), fn (string $target): bool => $target !== ''));

            $config = (array) ($node['config'] ?? []);

            if ((string) $node['type'] === AutomationNodeType::LogicJump->value
                && ($config['target'] ?? 'node') === 'node'
                && ($config['target_node_uid'] ?? '') !== '') {
                $targets[] = (string) $config['target_node_uid'];
            }

            foreach ($targets as $target) {
                if (isset($byUid[$target]) && ! isset($seen[$target])) {
                    $seen[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        return array_values(array_filter(
            $nodes,
            fn (array $node): bool => isset($seen[(string) $node['uid']]),
        ));
    }
}
