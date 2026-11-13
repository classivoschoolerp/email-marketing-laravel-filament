<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\UpgradeManagerPage;
use App\Models\UpgradeRun;
use App\Services\Upgrade\MaintenanceMode;
use App\Services\Upgrade\UpgradePermissions;
use App\Settings\OpsSettings;
use App\Support\Admin\AdminModules;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;
use Tests\Support\RecordingMaintenanceMode;
use Tests\Support\UpgradeTestHelpers;

/*
 * WP-41 §6.2 F-6.2-001/002/007 — the admin dashboard: current version, latest
 * version, changelog, pre-flight board, the guarded one-click upgrade, the dry
 * run and the history log. Plus the `system.upgrade` permission gate, which is
 * the real trust boundary (docs/upgrades.md §1).
 */

const PAGE_FEED = 'https://releases.example.test/latest.json';

beforeEach(function (): void {
    $this->target = UpgradeTestHelpers::useTempTarget();
    UpgradeTestHelpers::useTempBackups();

    config([
        'emp.core_version' => '1.0.0',
        'emp-upgrade.release.endpoint' => PAGE_FEED,
        'emp-upgrade.post_upgrade_commands' => ['inspire'],
    ]);

    $settings = app(OpsSettings::class);
    $settings->backup_include_uploads = false;
    $settings->save();

    app()->instance(MaintenanceMode::class, new RecordingMaintenanceMode);
});

afterEach(fn () => UpgradeTestHelpers::cleanup());

/** @param array<string, mixed> $feedOverrides */
function publishForPage(array $feedOverrides = [], string $version = '1.1.0'): void
{
    $zip = UpgradeTestHelpers::releaseZip(UpgradeTestHelpers::releaseFiles($version));

    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed($version, $zip['checksum'], $feedOverrides)
        ),
        'releases.example.test/emp-*' => Http::response(file_get_contents($zip['path'])),
    ]);
}

// ------------------------------------------------------------ permission

it('declares system.upgrade in the assignable permission catalogue', function (): void {
    expect(AdminModules::EXTRA_PERMISSIONS)->toContain(UpgradePermissions::MANAGE)
        ->and(UpgradePermissions::MANAGE)->toBe('system.upgrade');
});

it('denies the upgrade manager to an admin without system.upgrade', function (): void {
    AdminTestHelpers::actingAdmin(['logs.view']);

    expect(UpgradeManagerPage::canAccess())->toBeFalse();

    $this->get(UpgradeManagerPage::getUrl(panel: 'admin'))->assertForbidden();
});

it('allows an admin holding system.upgrade', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage();

    expect(UpgradeManagerPage::canAccess())->toBeTrue();

    $this->get(UpgradeManagerPage::getUrl(panel: 'admin'))->assertOk();
});

it('allows a Super Admin without the explicit permission', function (): void {
    $user = AdminTestHelpers::superAdmin();
    $this->actingAs($user, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(UpgradeManagerPage::canAccess())->toBeTrue();
});

// -------------------------------------------------------------- the page

it('shows current version, latest version and the changelog (F-6.2-001)', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage();

    $this->get(UpgradeManagerPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('1.0.0')
        ->assertSee('1.1.0')
        ->assertSee('Faster sending');
});

it('renders the pre-flight board and the protected paths (F-6.2-003/006)', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage();

    $this->get(UpgradeManagerPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('upgrade.checks.disk_space.label'))
        ->assertSee(__('upgrade.checks.pending_migrations.label'))
        ->assertSee('storage/app/plugins')
        ->assertSee('.env');
});

it('reports "no release feed configured" rather than "up to date"', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    config(['emp-upgrade.release.endpoint' => null]);

    $this->get(UpgradeManagerPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('upgrade.status.disabled'));
});

// ------------------------------------------------------------- actions

it('runs a dry run from the page and writes nothing', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage();

    Livewire::test(UpgradeManagerPage::class)
        ->callAction('dryRun')
        ->assertHasNoActionErrors();

    $run = UpgradeRun::query()->latest('id')->firstOrFail();

    expect($run->dry_run)->toBeTrue()
        ->and($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse();
});

it('refuses the upgrade action without the exact typed phrase (F-6.2-002)', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage();

    Livewire::test(UpgradeManagerPage::class)
        ->callAction('upgrade', ['confirmation' => 'upgrade']);

    expect(UpgradeRun::query()->count())->toBe(0)
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse();
});

it('applies the upgrade with the typed phrase and records it (F-6.2-002/007)', function (): void {
    $admin = AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage();

    Livewire::test(UpgradeManagerPage::class)
        ->callAction('upgrade', ['confirmation' => 'UPGRADE'])
        ->assertHasNoActionErrors();

    $run = UpgradeRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and($run->user_id)->toBe($admin->getKey())
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeTrue();

    // …and shows up in the history log on the next render.
    $this->get(UpgradeManagerPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('upgrade.history.statuses.success'));
});

it('hides the upgrade action when the release is announced but not installable', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage(['checksum' => '']);

    Livewire::test(UpgradeManagerPage::class)
        ->assertActionHidden('upgrade')
        ->assertActionHidden('dryRun');

    $this->get(UpgradeManagerPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('upgrade.release.not_installable'));
});

it('rolls a previous run back from the history log (F-6.2-004)', function (): void {
    AdminTestHelpers::actingAdmin([UpgradePermissions::MANAGE]);
    publishForPage();

    Livewire::test(UpgradeManagerPage::class)->callAction('upgrade', ['confirmation' => 'UPGRADE']);

    $run = UpgradeRun::query()->latest('id')->firstOrFail();

    Livewire::test(UpgradeManagerPage::class)->call('rollbackRun', $run->id);

    expect(file_get_contents($this->target.'/app/Services/Old.php'))->toContain('v1.0.0')
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse()
        ->and($run->refresh()->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK);
});
