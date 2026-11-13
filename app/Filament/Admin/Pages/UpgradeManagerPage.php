<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\UpgradeRun;
use App\Models\User;
use App\Services\Upgrade\ReleaseChecker;
use App\Services\Upgrade\UpgradeException;
use App\Services\Upgrade\UpgradePermissions;
use App\Services\Upgrade\UpgradePreflight;
use App\Services\Upgrade\UpgradeRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * §6.2 F-6.2-001..008 — the live upgrade manager (WP-41).
 *
 * Current version, latest available version, changelog, the pre-upgrade check
 * board, a dry run, the guarded one-click upgrade and the history log, all on
 * one page — because an operator deciding whether to upgrade needs those
 * facts side by side, not spread over four screens.
 *
 * Gated by `system.upgrade` using the same canAccess() shape as
 * {@see PluginHooksPage}. Three guards sit between an admin and a destructive
 * run: the permission, Filament's confirmation modal, and a typed
 * confirmation phrase checked server-side by
 * {@see UpgradeRunner::assertConfirmed()} — the button alone can never do it.
 */
class UpgradeManagerPage extends Page
{
    public const PERMISSION = UpgradePermissions::MANAGE;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-circle';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.upgrade-manager';

    protected static ?string $slug = 'system/upgrades';

    protected static ?int $navigationSort = 145;

    public static function canAccess(): bool
    {
        return UpgradePermissions::allows(auth('admin')->user());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('upgrade.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('upgrade.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('upgrade.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('upgrade.subheading');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $checker = app(ReleaseChecker::class);
        $result = $checker->check();
        $release = $result['release'];

        return [
            'result' => $result,
            'release' => $release?->toArray(),
            'installable' => $release?->isInstallable() ?? false,
            'upgradeAvailable' => $result['status'] === ReleaseChecker::STATUS_AVAILABLE,
            'channel' => $checker->channel(),
            'checks' => app(UpgradePreflight::class)->run($release),
            'runs' => UpgradeRun::query()->with('user')->latest('id')->limit(25)->get(),
            'confirmationPhrase' => app(UpgradeRunner::class)->confirmationPhrase(),
            'protectedPaths' => (array) config('emp-upgrade.protected_paths', []),
        ];
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label(__('upgrade.actions.check'))
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    app(ReleaseChecker::class)->check(force: true);

                    Notification::make()->success()->title(__('upgrade.notifications.checked'))->send();
                }),

            Action::make('dryRun')
                ->label(__('upgrade.actions.dry_run'))
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->visible(fn (): bool => $this->hasInstallableRelease())
                ->requiresConfirmation()
                ->modalDescription(__('upgrade.dry_run.explanation'))
                ->action(function (): void {
                    $release = app(ReleaseChecker::class)->latest();

                    if ($release === null) {
                        Notification::make()->warning()->title(__('upgrade.errors.no_release'))->send();

                        return;
                    }

                    try {
                        $run = app(UpgradeRunner::class)->dryRun($release, $this->actor());
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    $this->announce($run, dryRun: true);
                }),

            Action::make('upgrade')
                ->label(__('upgrade.actions.upgrade'))
                ->icon('heroicon-o-arrow-up-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->hasInstallableRelease())
                ->requiresConfirmation()
                ->modalHeading(__('upgrade.confirm.heading'))
                ->modalDescription(fn (): HtmlString => new HtmlString(e(__('upgrade.confirm.body'))))
                ->form([
                    Placeholder::make('warning')
                        ->label('')
                        ->content(fn (): HtmlString => new HtmlString(e(__('upgrade.confirm.body')))),
                    TextInput::make('confirmation')
                        ->label(__('upgrade.confirm.field', [
                            'phrase' => app(UpgradeRunner::class)->confirmationPhrase(),
                        ]))
                        ->placeholder(__('upgrade.confirm.placeholder'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $release = app(ReleaseChecker::class)->latest();

                    if ($release === null) {
                        Notification::make()->warning()->title(__('upgrade.errors.no_release'))->send();

                        return;
                    }

                    try {
                        $run = app(UpgradeRunner::class)->apply(
                            $release,
                            (string) ($data['confirmation'] ?? ''),
                            $this->actor(),
                        );
                    } catch (UpgradeException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    $this->announce($run, dryRun: false);
                }),
        ];
    }

    /** Restore the file tree captured by a previous run (F-6.2-004). */
    public function rollbackRun(int $runId): void
    {
        if (! self::canAccess()) {
            abort(403);
        }

        $run = UpgradeRun::query()->find($runId);

        if ($run === null || ! app(UpgradeRunner::class)->rollback($run)) {
            Notification::make()->danger()->title(__('upgrade.notifications.rollback_unavailable'))->send();

            return;
        }

        Notification::make()->success()->title(__('upgrade.notifications.rollback_done'))->send();
    }

    private function announce(UpgradeRun $run, bool $dryRun): void
    {
        if ($run->status === UpgradeRun::STATUS_BLOCKED) {
            Notification::make()->danger()
                ->title(__('upgrade.notifications.blocked'))
                ->body((string) $run->error)
                ->send();

            return;
        }

        if ($dryRun) {
            Notification::make()->success()
                ->title(__('upgrade.notifications.dry_run_done'))
                ->body((string) $run->notes)
                ->send();

            return;
        }

        if ($run->status === UpgradeRun::STATUS_SUCCESS) {
            Notification::make()->success()
                ->title(__('upgrade.notifications.success', ['version' => (string) $run->to_version]))
                ->body((string) $run->notes)
                ->send();

            return;
        }

        Notification::make()->danger()
            ->title($run->status === UpgradeRun::STATUS_ROLLED_BACK
                ? __('upgrade.notifications.rolled_back')
                : __('upgrade.notifications.failed', ['error' => (string) $run->error]))
            ->body((string) $run->notes)
            ->send();
    }

    private function hasInstallableRelease(): bool
    {
        $result = app(ReleaseChecker::class)->check();

        return $result['status'] === ReleaseChecker::STATUS_AVAILABLE
            && $result['release']?->isInstallable() === true;
    }

    private function actor(): ?User
    {
        $user = auth('admin')->user();

        return $user instanceof User ? $user : null;
    }
}
