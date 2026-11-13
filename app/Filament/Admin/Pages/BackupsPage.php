<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Services\Ops\BackupManager;
use App\Services\Reports\SystemInfoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * §6.6 F-6.6-006 — built-in backup hooks with schedule and retention.
 *
 * Lists the artefacts `backups:run` produced (DB dump + uploads archive),
 * downloads/deletes them, runs the job on demand, and shows the retention +
 * schedule in force. Restore is intentionally MANUAL: the page prints exact
 * commands rather than offering a button that drops a live database.
 */
class BackupsPage extends Page
{
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.backups';

    protected static ?string $slug = 'ops/backups';

    protected static ?int $navigationSort = 112;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.backups.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ops.backups.title');
    }

    public function manager(): BackupManager
    {
        return app(BackupManager::class);
    }

    /** @return list<array<string, mixed>> */
    public function backups(): array
    {
        return $this->manager()->list();
    }

    public function formatBytes(int $bytes): string
    {
        return app(SystemInfoService::class)->formatBytes($bytes);
    }

    /** @return list<array{title: string, command: string}> */
    public function restoreInstructions(): array
    {
        return $this->manager()->restoreInstructions();
    }

    public function download(string $name): ?BinaryFileResponse
    {
        $path = $this->manager()->pathFor($name);

        if ($path === null) {
            Notification::make()->danger()->title(__('ops.backups.not_found'))->send();

            return null;
        }

        return response()->download($path);
    }

    public function deleteBackup(string $name): void
    {
        if ($this->manager()->delete($name)) {
            Notification::make()->success()->title(__('ops.backups.deleted', ['name' => $name]))->send();

            return;
        }

        Notification::make()->danger()->title(__('ops.backups.not_found'))->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBackup')
                ->label(__('ops.backups.run_now'))
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $exit = \Illuminate\Support\Facades\Artisan::call('backups:run');
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    if ($exit === 0) {
                        Notification::make()->success()->title(__('ops.backups.run_finished'))->send();

                        return;
                    }

                    Notification::make()->danger()->title(__('ops.backups.run_failed'))->send();
                }),

            Action::make('prune')
                ->label(__('ops.backups.prune_now'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    $deleted = $this->manager()->prune();

                    Notification::make()
                        ->success()
                        ->title(__('ops.backups.pruned', ['count' => $deleted]))
                        ->send();
                }),
        ];
    }
}
