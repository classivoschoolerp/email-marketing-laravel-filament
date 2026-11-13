<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Models\WorkerNode;
use App\Services\Ops\WorkerRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * §6.6 F-6.6-008 worker/node registry + F-6.6-009 graceful restart/drain.
 *
 * Shows every process that has checked in (node, pid, queues, uptime, current
 * job, counters) and marks stale rows. The header actions issue the two
 * signals a distributed fleet understands:
 *   - `queue:restart` — Laravel's own broadcast restart flag; each worker
 *     finishes its CURRENT job and exits, and Supervisor restarts it;
 *   - the WP-24 drain flag — a cache marker `queue:heartbeat` polls, so nodes
 *     stop taking new work even where `queue:restart` cannot reach them
 *     (different cache/queue backends, D-04).
 */
class WorkerNodesPage extends Page
{
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static string $view = 'filament.admin.pages.worker-nodes';

    protected static ?string $slug = 'ops/workers';

    protected static ?int $navigationSort = 113;

    public static function getNavigationGroup(): ?string
    {
        return __('infra.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.workers.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ops.workers.title');
    }

    public function registry(): WorkerRegistry
    {
        return app(WorkerRegistry::class);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, WorkerNode> */
    public function nodes()
    {
        return WorkerNode::query()->orderBy('node')->orderBy('pid')->get();
    }

    public function draining(): bool
    {
        return $this->registry()->draining();
    }

    /** @return array<string, mixed>|null */
    public function drainState(): ?array
    {
        return $this->registry()->drainState();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('restart')
                ->label(__('ops.workers.restart'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription(__('ops.workers.restart_help'))
                ->action(function (): void {
                    try {
                        Artisan::call('queue:restart');
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('ops.workers.restart_sent'))->send();
                }),

            Action::make('drain')
                ->label(__('ops.workers.drain'))
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('ops.workers.drain_help'))
                ->visible(fn (): bool => ! $this->draining())
                ->action(function (): void {
                    $this->registry()->drain(auth('admin')->user()?->email);

                    Notification::make()->success()->title(__('ops.workers.drain_set'))->send();
                }),

            Action::make('cancelDrain')
                ->label(__('ops.workers.cancel_drain'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => $this->draining())
                ->action(function (): void {
                    $this->registry()->cancelDrain();

                    Notification::make()->success()->title(__('ops.workers.drain_cleared'))->send();
                }),

            Action::make('prune')
                ->label(__('ops.workers.prune'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    $deleted = $this->registry()->prune();

                    Notification::make()
                        ->success()
                        ->title(__('ops.workers.pruned', ['count' => $deleted]))
                        ->send();
                }),
        ];
    }
}
