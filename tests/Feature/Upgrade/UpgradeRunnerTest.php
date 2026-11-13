<?php

declare(strict_types=1);

use App\Models\UpgradeRun;
use App\Services\Upgrade\MaintenanceMode;
use App\Services\Upgrade\ReleaseChecker;
use App\Services\Upgrade\ReleaseInfo;
use App\Services\Upgrade\UpgradeException;
use App\Services\Upgrade\UpgradeRunner;
use App\Settings\OpsSettings;
use Illuminate\Support\Facades\Http;
use Tests\Support\AdminTestHelpers;
use Tests\Support\RecordingMaintenanceMode;
use Tests\Support\UpgradeTestHelpers;

/*
 * WP-41 §6.2 F-6.2-002 (one-click upgrade: download → verify → backup →
 * migrate → clear caches → report), F-6.2-004 (pre-upgrade backup with
 * rollback), F-6.2-005 (maintenance mode), F-6.2-006 (no reinstall, no data
 * loss) and F-6.2-007 (history log).
 *
 * EVERY test here runs against a throwaway temp directory injected via
 * `emp-upgrade.target_path`. Nothing in this file may touch the real
 * installation — UpgradeTarget::assertSafe() enforces that independently.
 */

const RUNNER_FEED = 'https://releases.example.test/latest.json';

beforeEach(function (): void {
    $this->target = UpgradeTestHelpers::useTempTarget();
    $this->backups = UpgradeTestHelpers::useTempBackups();

    config([
        'emp.core_version' => '1.0.0',
        'emp-upgrade.release.endpoint' => RUNNER_FEED,
        // The real list ends with `filament:assets` (D-87); running artisan
        // cache commands against the developer's checkout from a test would be
        // a side effect on the real installation, which this WP forbids.
        'emp-upgrade.post_upgrade_commands' => ['inspire'],
    ]);

    $settings = app(OpsSettings::class);
    $settings->backup_include_uploads = false;
    $settings->save();

    $this->maintenance = new RecordingMaintenanceMode;
    app()->instance(MaintenanceMode::class, $this->maintenance);
});

afterEach(fn () => UpgradeTestHelpers::cleanup());

/**
 * Publish a release: build the zip, fake the feed with its real sha256 and
 * fake the download to serve the same bytes.
 *
 * @param  array<string, string>|null  $files
 * @param  array<string, mixed>  $feedOverrides
 */
function publishRelease(?array $files = null, array $feedOverrides = [], string $version = '1.1.0', ?string $prefix = null): array
{
    $files ??= UpgradeTestHelpers::releaseFiles($version);
    $zip = UpgradeTestHelpers::releaseZip($files, $prefix);
    $feed = UpgradeTestHelpers::feed($version, $zip['checksum'], $feedOverrides);

    Http::fake([
        'releases.example.test/latest.json*' => Http::response($feed),
        'releases.example.test/emp-*' => Http::response(file_get_contents($zip['path']), 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    return $zip;
}

function latestRelease(): ReleaseInfo
{
    return app(ReleaseChecker::class)->latest(force: true);
}

// -------------------------------------------------- F-6.2-002 happy path

it('downloads, verifies, backs up, applies, migrates and reports (F-6.2-002)', function (): void {
    publishRelease();

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and($run->from_version)->toBe('1.0.0')
        ->and($run->to_version)->toBe('1.1.0')
        ->and($run->dry_run)->toBeFalse()
        ->and($run->finished_at)->not->toBeNull();

    $steps = collect($run->stepRows())->keyBy('key');

    foreach ([
        UpgradeRun::STEP_PREFLIGHT,
        UpgradeRun::STEP_DOWNLOAD,
        UpgradeRun::STEP_VERIFY,
        UpgradeRun::STEP_BACKUP,
        UpgradeRun::STEP_MAINTENANCE_ON,
        UpgradeRun::STEP_APPLY,
        UpgradeRun::STEP_MIGRATE,
        UpgradeRun::STEP_CACHES,
        UpgradeRun::STEP_MAINTENANCE_OFF,
        UpgradeRun::STEP_CLEANUP,
    ] as $step) {
        expect($steps[$step]['status'])->toBe('ok', 'step '.$step);
    }

    // Files actually landed in the temp target.
    expect(file_get_contents($this->target.'/app/Services/Old.php'))->toContain('v1.1.0')
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeTrue()
        ->and(file_get_contents($this->target.'/config/emp.php'))->toContain("'1.1.0'");
});

it('reports the file plan: what was added, replaced and skipped (F-6.2-002)', function (): void {
    publishRelease();

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');
    $plan = $run->file_plan;

    expect($plan['add'])->toContain('app/Services/New.php')
        ->and($plan['replace'])->toContain('app/Services/Old.php', 'config/emp.php', 'public/index.php')
        // created_files is the rollback ledger: exactly the files that did not
        // exist before, so a rollback deletes them rather than orphaning them.
        // `release.json` is part of the package and lands like any other file.
        ->and($run->created_files)->toBe(['app/Services/New.php', 'release.json']);
});

it('unwraps a single top-level release folder', function (): void {
    publishRelease(prefix: 'emp-1.1.0');

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeTrue()
        ->and(is_dir($this->target.'/emp-1.1.0'))->toBeFalse();
});

// ------------------------------------- F-6.2-006 no reinstall, no data loss

it('NEVER writes .env, plugins, themes, backups or uploads (F-6.2-006)', function (): void {
    // A hostile/careless release that ships every protected path.
    publishRelease(UpgradeTestHelpers::releaseFiles('1.1.0', [
        '.env' => "APP_KEY=base64:ATTACKER\n",
        'storage/app/plugins/acme-relay/plugin.json' => '{"slug":"hijacked"}',
        'storage/app/themes/midnight/theme.json' => '{"slug":"hijacked"}',
        'storage/app/backups/backup-20260101-000000.sqlite' => 'DESTROYED',
        'storage/app/public/customers/c1/logo.png' => 'DESTROYED',
    ]));

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and(file_get_contents($this->target.'/.env'))->toBe("APP_KEY=base64:local-secret\n")
        ->and(file_get_contents($this->target.'/storage/app/plugins/acme-relay/plugin.json'))->toBe('{"slug":"acme-relay"}')
        ->and(file_get_contents($this->target.'/storage/app/themes/midnight/theme.json'))->toBe('{"slug":"midnight"}')
        ->and(file_get_contents($this->target.'/storage/app/backups/backup-20260101-000000.sqlite'))->toBe('PREVIOUS BACKUP')
        ->and(file_get_contents($this->target.'/storage/app/public/customers/c1/logo.png'))->toBe('PNG');

    // And it is recorded, not silently swallowed.
    expect($run->file_plan['protected'])->toContain(
        '.env',
        'storage/app/plugins/acme-relay/plugin.json',
        'storage/app/themes/midnight/theme.json',
        'storage/app/backups/backup-20260101-000000.sqlite',
        'storage/app/public/customers/c1/logo.png',
    );
});

it('rejects an archive that tries to escape the target directory', function (): void {
    publishRelease(['../../evil.php' => '<?php // pwned', 'config/emp.php' => '<?php return [];']);

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK)
        ->and($run->error)->toContain('unsafe path')
        ->and(is_file(dirname($this->target, 2).'/evil.php'))->toBeFalse();
});

it('rejects an archive shipping a native binary', function (): void {
    publishRelease(UpgradeTestHelpers::releaseFiles('1.1.0', ['bin/agent.so' => 'ELF']));

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK)
        ->and($run->error)->toContain('blocked file type');
});

// ----------------------------------------------------- checksum + manifest

it('aborts BEFORE any write when the checksum does not match (F-6.2-002)', function (): void {
    $zip = UpgradeTestHelpers::releaseZip(UpgradeTestHelpers::releaseFiles());

    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.1.0', str_repeat('0', 64))
        ),
        'releases.example.test/emp-*' => Http::response(file_get_contents($zip['path'])),
    ]);

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK)
        ->and($run->error)->toContain('Checksum verification FAILED')
        ->and(file_get_contents($this->target.'/app/Services/Old.php'))->toContain('v1.0.0')
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse();

    // Nothing destructive ran: no backup, no maintenance mode.
    expect($this->maintenance->calls)->toBe([])
        ->and(collect($run->stepRows())->firstWhere('key', UpgradeRun::STEP_BACKUP)['status'])->toBe('pending');
});

it('rejects a package whose release.json disagrees with the feed', function (): void {
    publishRelease(UpgradeTestHelpers::releaseFiles('1.1.0', [
        'release.json' => json_encode(['version' => '9.9.9']),
    ]));

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK)
        ->and($run->error)->toContain('9.9.9');
});

// ------------------------------------------------ F-6.2-004 backup + roll back

it('takes a real pre-upgrade backup through the existing BackupManager (F-6.2-004)', function (): void {
    publishRelease();

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->backup_database)->not->toBeNull()
        ->and(is_file((string) $run->backup_database))->toBeTrue()
        ->and(file_get_contents((string) $run->backup_database))->toBe('SQLITE FILE CONTENTS')
        ->and(dirname((string) $run->backup_database))->toBe($this->backups['directory']);
});

it('refuses to continue when the pre-upgrade backup produced nothing (F-6.2-004)', function (): void {
    publishRelease();

    // A database file the backup command cannot copy — `backups:run` records a
    // failed cron run and produces no artefact.
    config(['database.connections.sqlite.database' => $this->backups['directory'].'/missing.sqlite']);

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK)
        ->and($run->error)->toContain('pre-upgrade backup')
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse()
        ->and(file_get_contents($this->target.'/app/Services/Old.php'))->toContain('v1.0.0');
});

it('rolls every applied file back when a later step fails (F-6.2-004)', function (): void {
    // A directory sitting where the release wants to write a file makes the
    // write fail part-way through the apply step, after other files landed.
    @mkdir($this->target.'/app/Services/New.php', 0775, true);

    publishRelease();

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK)
        // The replaced files are back at their pre-upgrade contents.
        ->and(file_get_contents($this->target.'/app/Services/Old.php'))->toContain('v1.0.0')
        ->and(file_get_contents($this->target.'/config/emp.php'))->toContain("'1.0.0'")
        ->and(file_get_contents($this->target.'/public/index.php'))->toContain('v1.0.0')
        ->and($run->notes)->toContain('restored')
        // Maintenance mode was lifted again even though the run failed.
        ->and($this->maintenance->calls)->toContain('up')
        ->and($this->maintenance->isDown())->toBeFalse();
});

it('can restore a previous run from its snapshot on demand (F-6.2-004)', function (): void {
    publishRelease();

    $runner = app(UpgradeRunner::class);
    $run = $runner->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and($run->snapshot_path)->not->toBeNull();

    expect($runner->rollback($run->refresh()))->toBeTrue()
        ->and(file_get_contents($this->target.'/app/Services/Old.php'))->toContain('v1.0.0')
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse()
        ->and($run->refresh()->status)->toBe(UpgradeRun::STATUS_ROLLED_BACK);
});

// -------------------------------------------- F-6.2-005 maintenance mode

it('shields the site with maintenance mode across the destructive steps (F-6.2-005)', function (): void {
    publishRelease();

    app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($this->maintenance->calls)->toBe(['down', 'up'])
        ->and($this->maintenance->isDown())->toBeFalse();
});

it('continues unshielded, and says so, when maintenance mode cannot be enabled (F-6.2-005)', function (): void {
    $this->maintenance->downSucceeds = false;
    publishRelease();

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');
    $steps = collect($run->stepRows())->keyBy('key');

    expect($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and($steps[UpgradeRun::STEP_MAINTENANCE_ON]['status'])->toBe('warning');
});

// ---------------------------------------------------------- dry run + guards

it('changes nothing in dry-run mode and still reports the full plan', function (): void {
    publishRelease();

    $run = app(UpgradeRunner::class)->dryRun(latestRelease());

    expect($run->status)->toBe(UpgradeRun::STATUS_SUCCESS)
        ->and($run->dry_run)->toBeTrue()
        ->and($run->file_plan['add'])->toContain('app/Services/New.php')
        ->and($run->notes)->toContain('Nothing was written')
        // Target untouched.
        ->and(file_get_contents($this->target.'/app/Services/Old.php'))->toContain('v1.0.0')
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse()
        // No backup, no maintenance mode.
        ->and($run->backup_database)->toBeNull()
        ->and($this->maintenance->calls)->toBe([]);

    $steps = collect($run->stepRows())->keyBy('key');

    expect($steps[UpgradeRun::STEP_VERIFY]['status'])->toBe('ok')
        ->and($steps[UpgradeRun::STEP_APPLY]['status'])->toBe('skipped')
        ->and($steps[UpgradeRun::STEP_MIGRATE]['status'])->toBe('skipped');
});

it('requires the exact typed confirmation phrase (F-6.2-002 guard)', function (): void {
    publishRelease();
    $release = latestRelease();
    $runner = app(UpgradeRunner::class);

    expect(fn () => $runner->apply($release, 'upgrade'))->toThrow(UpgradeException::class)
        ->and(fn () => $runner->apply($release, ''))->toThrow(UpgradeException::class)
        ->and(fn () => $runner->apply($release, 'yes'))->toThrow(UpgradeException::class);

    // Nothing was recorded and nothing was written.
    expect(UpgradeRun::query()->count())->toBe(0)
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse();
});

it('refuses outright to run against the real installation (test-suite safety net)', function (): void {
    config(['emp-upgrade.target_path' => null]);
    publishRelease();

    expect(fn () => app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE'))
        ->toThrow(UpgradeException::class);

    expect(UpgradeRun::query()->count())->toBe(0);
});

it('records a BLOCKED run and downloads nothing when pre-flight fails (F-6.2-003)', function (): void {
    publishRelease(feedOverrides: ['min_php' => '99.0.0']);

    $run = app(UpgradeRunner::class)->apply(latestRelease(), 'UPGRADE');

    expect($run->status)->toBe(UpgradeRun::STATUS_BLOCKED)
        ->and($run->error)->toContain('99.0.0')
        ->and(collect($run->stepRows())->firstWhere('key', UpgradeRun::STEP_DOWNLOAD)['status'])->toBe('pending')
        ->and($this->maintenance->calls)->toBe([])
        ->and(is_file($this->target.'/app/Services/New.php'))->toBeFalse();
});

// --------------------------------------------------- F-6.2-007 history log

it('logs every attempt, dry runs and blocked runs included (F-6.2-007)', function (): void {
    $admin = AdminTestHelpers::admin(['system.upgrade']);
    publishRelease();

    $runner = app(UpgradeRunner::class);
    $runner->dryRun(latestRelease(), $admin);
    $runner->apply(latestRelease(), 'UPGRADE', $admin);

    $runs = UpgradeRun::query()->orderBy('id')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]->dry_run)->toBeTrue()
        ->and($runs[1]->dry_run)->toBeFalse()
        ->and($runs[1]->user_id)->toBe($admin->getKey())
        ->and($runs[1]->checks)->not->toBeEmpty()
        ->and($runs[1]->steps)->not->toBeEmpty()
        ->and($runs[1]->checksum)->toHaveLength(64)
        ->and($runs->every(fn (UpgradeRun $run): bool => $run->isTerminal()))->toBeTrue();
});

it('ships filament:assets in the default post-upgrade command list (D-87)', function (): void {
    // Restore the shipped default the beforeEach overrode for test safety.
    $default = require base_path('config/emp-upgrade.php');

    expect($default['post_upgrade_commands'])->toContain('filament:assets');
});
