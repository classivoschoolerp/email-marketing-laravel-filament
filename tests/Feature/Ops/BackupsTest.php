<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\BackupsPage;
use App\Models\CronRun;
use App\Services\Ops\BackupManager;
use App\Settings\OpsSettings;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §6.6 F-6.6-006 — built-in backup hooks (DB + uploads) with schedule and
// retention: the WP-13 `backups:run` job extended, plus the admin page.

beforeEach(function (): void {
    $this->scratch = storage_path('framework/testing/wp24-backups-'.uniqid());
    File::ensureDirectoryExists($this->scratch);
    config(['emp-sending.backups.directory' => $this->scratch]);
});

afterEach(function (): void {
    File::deleteDirectory($this->scratch);
});

it('archives uploads next to the database dump and prunes both kinds', function (): void {
    $manager = app(BackupManager::class);

    // Something to archive.
    File::ensureDirectoryExists(storage_path('app/public/wp24'));
    File::put(storage_path('app/public/wp24/demo.txt'), 'demo asset');

    $archive = $manager->backupUploads('20260808-120000');

    if ($manager->zipSupported()) {
        expect($archive)->not->toBeNull()
            ->and(File::exists((string) $archive))->toBeTrue()
            ->and(basename((string) $archive))->toBe('uploads-20260808-120000.zip');
    } else {
        // ext-zip absent: the DB backup must still work, honestly reported.
        expect($archive)->toBeNull();
    }

    // Retention prunes database dumps AND uploads archives.
    File::put($this->scratch.'/backup-20200101-000000.sqlite', 'old');
    File::put($this->scratch.'/uploads-20200101-000000.zip', 'old');
    File::put($this->scratch.'/unrelated.txt', 'keep me');
    touch($this->scratch.'/backup-20200101-000000.sqlite', now()->subDays(60)->getTimestamp());
    touch($this->scratch.'/uploads-20200101-000000.zip', now()->subDays(60)->getTimestamp());

    expect($manager->prune(7))->toBe(2)
        ->and(File::exists($this->scratch.'/unrelated.txt'))->toBeTrue();

    // 0 = keep forever (D-23).
    File::put($this->scratch.'/backup-20200102-000000.sqlite', 'old');
    touch($this->scratch.'/backup-20200102-000000.sqlite', now()->subDays(60)->getTimestamp());

    expect($manager->prune(0))->toBe(0);

    File::deleteDirectory(storage_path('app/public/wp24'));
});

it('lists only recognised backup files and refuses unsafe names', function (): void {
    $manager = app(BackupManager::class);

    File::put($this->scratch.'/backup-20260808-010000.sqlite', 'db');
    File::put($this->scratch.'/uploads-20260808-010000.zip', 'zip');
    File::put($this->scratch.'/evil.php', '<?php');

    $names = collect($manager->list())->pluck('name')->all();

    expect($names)->toHaveCount(2)
        ->and($names)->not->toContain('evil.php')
        ->and(collect($manager->list())->firstWhere('name', 'uploads-20260808-010000.zip')['type'])->toBe('uploads')
        ->and($manager->totalBytes())->toBeGreaterThan(0);

    // Path traversal and unknown names never resolve.
    expect($manager->pathFor('../../.env'))->toBeNull()
        ->and($manager->pathFor('evil.php'))->toBeNull()
        ->and($manager->pathFor('backup-20260808-010000.sqlite'))->not->toBeNull()
        ->and($manager->delete('../../.env'))->toBeFalse();
});

it('runs backups:run with the uploads archive and records the artefact count', function (): void {
    $dbFile = $this->scratch.'/source.sqlite';
    File::put($dbFile, 'SQLITE-CONTENT');
    config(['database.connections.sqlite.database' => $dbFile]);

    $settings = app(OpsSettings::class);
    $settings->backup_include_uploads = true;
    $settings->backup_retention_days = 7;
    $settings->save();

    File::ensureDirectoryExists(storage_path('app/public/wp24'));
    File::put(storage_path('app/public/wp24/demo.txt'), 'demo asset');

    $this->artisan('backups:run')->assertSuccessful();

    $run = CronRun::query()->where('job', 'backups:run')->latest('id')->firstOrFail();
    $manager = app(BackupManager::class);
    $files = collect($manager->list());

    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($files->where('type', 'database'))->toHaveCount(1)
        ->and(File::get($files->firstWhere('type', 'database')['path']))->toBe('SQLITE-CONTENT')
        ->and($run->processed)->toBe($manager->zipSupported() ? 2 : 1);

    if ($manager->zipSupported()) {
        expect($files->where('type', 'uploads'))->toHaveCount(1);
    }

    File::deleteDirectory(storage_path('app/public/wp24'));
});

it('skips the uploads archive when the setting is off', function (): void {
    $dbFile = $this->scratch.'/source.sqlite';
    File::put($dbFile, 'SQLITE-CONTENT');
    config(['database.connections.sqlite.database' => $dbFile]);

    $settings = app(OpsSettings::class);
    $settings->backup_include_uploads = false;
    $settings->save();

    $this->artisan('backups:run')->assertSuccessful();

    expect(collect(app(BackupManager::class)->list())->where('type', 'uploads'))->toHaveCount(0);
});

it('lists, downloads, deletes and prunes from the admin backups page', function (): void {
    File::put($this->scratch.'/backup-20260808-010000.sqlite', 'db-bytes');

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(BackupsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('backup-20260808-010000.sqlite')
        ->assertSee(__('ops.backups.restore.heading'))
        // Restore is documented, not automated.
        ->assertSee(__('ops.backups.restore.intro'));

    $page = Livewire::test(BackupsPage::class);

    $instructions = $page->instance()->restoreInstructions();

    expect($instructions)->toHaveCount(4)
        ->and($instructions[1]['command'])->toContain('backup-YYYYMMDD-HHMMSS');

    $page->call('download', 'backup-20260808-010000.sqlite')
        ->assertFileDownloaded('backup-20260808-010000.sqlite');

    $page->call('deleteBackup', 'backup-20260808-010000.sqlite');

    expect(File::exists($this->scratch.'/backup-20260808-010000.sqlite'))->toBeFalse();

    // A bogus name is rejected, not resolved.
    $page->call('deleteBackup', '../../.env');

    expect(File::exists(base_path('.env')))->toBeTrue();
});

it('runs a backup on demand from the page', function (): void {
    $dbFile = $this->scratch.'/source.sqlite';
    File::put($dbFile, 'SQLITE-CONTENT');
    config(['database.connections.sqlite.database' => $dbFile]);

    AdminTestHelpers::actingAdmin(['logs.view']);

    Livewire::test(BackupsPage::class)->callAction('runBackup');

    expect(collect(app(BackupManager::class)->list())->where('type', 'database'))->toHaveCount(1);

    // Prune action honours retention.
    $settings = app(OpsSettings::class);
    $settings->backup_retention_days = 1;
    $settings->save();

    File::put($this->scratch.'/backup-20200101-000000.sqlite', 'old');
    touch($this->scratch.'/backup-20200101-000000.sqlite', now()->subDays(30)->getTimestamp());

    Livewire::test(BackupsPage::class)->callAction('prune');

    expect(File::exists($this->scratch.'/backup-20200101-000000.sqlite'))->toBeFalse();
});
