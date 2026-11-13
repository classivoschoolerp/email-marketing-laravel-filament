<?php

declare(strict_types=1);

use App\Services\Upgrade\ReleaseInfo;
use App\Services\Upgrade\UpgradeException;
use App\Services\Upgrade\UpgradePreflight;
use App\Services\Upgrade\UpgradeTarget;
use Illuminate\Support\Facades\DB;
use Tests\Support\UpgradeTestHelpers;

/*
 * WP-41 §6.2 F-6.2-003 — pre-upgrade safety checks (disk space, PHP version,
 * DB connectivity, pending migrations) that BLOCK the upgrade when they fail.
 */

beforeEach(function (): void {
    UpgradeTestHelpers::useTempTarget();
    UpgradeTestHelpers::useTempBackups();
    config(['emp.core_version' => '1.0.0']);
});

afterEach(fn () => UpgradeTestHelpers::cleanup());

function release(array $overrides = []): ReleaseInfo
{
    return ReleaseInfo::fromArray(UpgradeTestHelpers::feed(
        '1.1.0',
        str_repeat('a', 64),
        $overrides,
    ));
}

/** @param list<array<string, mixed>> $checks */
function check(array $checks, string $key): array
{
    foreach ($checks as $row) {
        if ($row['key'] === $key) {
            return $row;
        }
    }

    throw new RuntimeException('No such check: '.$key);
}

it('passes every blocking check on a healthy installation (F-6.2-003)', function (): void {
    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release());

    expect($preflight->passes($checks))->toBeTrue()
        ->and($preflight->blockers($checks))->toBe([])
        ->and(check($checks, 'database')['status'])->toBe(UpgradePreflight::PASS)
        ->and(check($checks, 'pending_migrations')['status'])->toBe(UpgradePreflight::PASS)
        ->and(check($checks, 'php_version')['status'])->toBe(UpgradePreflight::PASS)
        ->and(check($checks, 'core_version')['status'])->toBe(UpgradePreflight::PASS)
        ->and(check($checks, 'target_writable')['status'])->toBe(UpgradePreflight::PASS)
        ->and(check($checks, 'zip_extension')['status'])->toBe(UpgradePreflight::PASS);
});

it('covers the four checks the spec names (F-6.2-003)', function (): void {
    $keys = array_column(app(UpgradePreflight::class)->run(release()), 'key');

    expect($keys)->toContain('disk_space', 'php_version', 'database', 'pending_migrations');
});

it('BLOCKS when the release needs a newer PHP than this host runs (F-6.2-003)', function (): void {
    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release(['min_php' => '99.0.0']));

    expect(check($checks, 'php_version')['status'])->toBe(UpgradePreflight::FAIL)
        ->and($preflight->passes($checks))->toBeFalse()
        ->and(implode(' ', $preflight->blockers($checks)))->toContain('99.0.0');
});

it('BLOCKS when the install has pending migrations (F-6.2-003)', function (): void {
    // Forget one applied migration so the migrator reports it as pending.
    $latest = DB::table('migrations')->orderByDesc('id')->first();
    DB::table('migrations')->where('id', $latest->id)->delete();

    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release());

    expect(check($checks, 'pending_migrations')['status'])->toBe(UpgradePreflight::FAIL)
        ->and($preflight->passes($checks))->toBeFalse();
});

it('BLOCKS when free disk space is below the configured floor (F-6.2-003)', function (): void {
    // A floor no machine satisfies — the check is arithmetic, not a mock.
    config(['emp-upgrade.preflight.min_free_disk_mb' => PHP_INT_MAX >> 30]);

    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release());

    expect(check($checks, 'disk_space')['status'])->toBe(UpgradePreflight::FAIL)
        ->and($preflight->passes($checks))->toBeFalse();
});

it('BLOCKS when the application directory does not exist or is not writable (F-6.2-003)', function (): void {
    config(['emp-upgrade.target_path' => sys_get_temp_dir().'/emp-upgrade-does-not-exist-'.uniqid()]);

    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release());

    expect(check($checks, 'target_writable')['status'])->toBe(UpgradePreflight::FAIL)
        ->and($preflight->passes($checks))->toBeFalse();
});

it('BLOCKS a release that is not newer than the installed version', function (): void {
    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release(['version' => '1.0.0']));

    expect(check($checks, 'core_version')['status'])->toBe(UpgradePreflight::FAIL);
});

it('BLOCKS a release whose requires_core constraint this install does not satisfy', function (): void {
    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release(['requires_core' => '>=2.0.0']));

    expect(check($checks, 'core_version')['status'])->toBe(UpgradePreflight::FAIL)
        ->and(check($checks, 'core_version')['detail'])->toContain('>=2.0.0');
});

it('BLOCKS a release that cannot be checksum-verified', function (): void {
    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release(['checksum' => '']));

    expect(check($checks, 'core_version')['status'])->toBe(UpgradePreflight::FAIL);
});

it('reports maintenance mode as advisory, never as a blocker', function (): void {
    config(['emp-upgrade.maintenance.enabled' => false]);

    $preflight = app(UpgradePreflight::class);
    $checks = $preflight->run(release());

    expect(check($checks, 'maintenance')['blocking'])->toBeFalse()
        ->and($preflight->passes($checks))->toBeTrue();
});

it('refuses to resolve the real installation as an upgrade target during tests', function (): void {
    config(['emp-upgrade.target_path' => null]);

    expect(fn () => app(UpgradeTarget::class)->assertSafe())
        ->toThrow(UpgradeException::class);
});

it('treats every protected path — and everything beneath it — as unwritable (F-6.2-006)', function (): void {
    $target = app(UpgradeTarget::class);

    expect($target->isProtected('.env'))->toBeTrue()
        ->and($target->isProtected('storage/app/plugins'))->toBeTrue()
        ->and($target->isProtected('storage/app/plugins/acme-relay/src/Plugin.php'))->toBeTrue()
        ->and($target->isProtected('storage/app/themes/midnight/theme.json'))->toBeTrue()
        ->and($target->isProtected('storage/app/backups/backup-1.sqlite'))->toBeTrue()
        ->and($target->isProtected('storage/app/public/customers/c1/logo.png'))->toBeTrue()
        ->and($target->isProtected('app/Services/Old.php'))->toBeFalse()
        ->and($target->isProtected('config/emp.php'))->toBeFalse();
});
