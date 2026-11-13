<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Models\UpgradeRun;
use App\Models\User;
use App\Plugins\CoreVersion;
use App\Support\Files;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-click upgrade (spec §6.2 F-6.2-002..007, WP-41).
 *
 * download → verify checksum → backup → maintenance on → apply files →
 * migrate → clear caches → maintenance off → cleanup, with every step
 * recorded on an {@see UpgradeRun} row as it happens (F-6.2-007) and a
 * file-level rollback snapshot taken before the first byte is overwritten
 * (F-6.2-004).
 *
 * THE FOUR RULES THIS CLASS EXISTS TO ENFORCE
 * -------------------------------------------
 * 1. **Nothing outside `UpgradeTarget::path()` is ever written.** That path
 *    defaults to base_path() and is injected with a temp directory by tests;
 *    UpgradeTarget::assertSafe() refuses base_path() under APP_ENV=testing.
 * 2. **Protected paths are never written** (F-6.2-006). `.env`, plugins,
 *    themes, backups and the upload trees are skipped and the skip is
 *    recorded, so "no data loss" is an assertion in the file plan rather than
 *    a promise in a README.
 * 3. **Nothing destructive happens until the checksum matches.** Backup,
 *    maintenance mode and the first write all come strictly after
 *    verification of a release the feed said was installable.
 * 4. **A failure after the first write always attempts a full file restore**,
 *    and when it cannot complete one the run is marked `failed` with the
 *    snapshot path recorded — that row is the entry point for the recovery
 *    procedure in docs/upgrades.md.
 *
 * DATABASE MIGRATIONS ARE NOT ROLLED BACK. `migrate:rollback` on a release
 * whose migrations half-applied is how data gets destroyed; the documented
 * recovery is to restore the pre-upgrade dump this runner just took. That is
 * a deliberate limitation, stated here and in the docs, not an oversight.
 */
class UpgradeRunner
{
    public function __construct(
        private readonly UpgradePreflight $preflight,
        private readonly UpgradeBackup $backup,
        private readonly MaintenanceMode $maintenance,
        private readonly UpgradeTarget $target,
    ) {}

    /**
     * Files the CURRENT run has created, in memory.
     *
     * `upgrade_runs.created_files` is written every 250 files rather than on
     * every file (one DB write per file would double the cost of applying a
     * release), so the persisted list lags. A rollback triggered by a failure
     * mid-apply must delete everything created up to that instant, not
     * everything that happened to be flushed — hence this in-memory ledger,
     * which is authoritative for the run that is still in progress.
     *
     * @var list<string>
     */
    private array $createdFiles = [];

    /**
     * Everything an upgrade would do, without touching the installation:
     * pre-flight, download, checksum verification and the full file plan.
     */
    public function dryRun(ReleaseInfo $release, ?User $actor = null): UpgradeRun
    {
        return $this->execute($release, $actor, dryRun: true);
    }

    /**
     * @throws UpgradeException when the typed confirmation does not match
     */
    public function apply(ReleaseInfo $release, string $confirmation, ?User $actor = null): UpgradeRun
    {
        $this->assertConfirmed($confirmation);

        return $this->execute($release, $actor, dryRun: false);
    }

    /** The exact phrase an operator must type to arm a real upgrade. */
    public function confirmationPhrase(): string
    {
        return (string) config('emp-upgrade.confirmation_phrase', 'UPGRADE');
    }

    /** @throws UpgradeException */
    public function assertConfirmed(string $confirmation): void
    {
        if (! hash_equals($this->confirmationPhrase(), trim($confirmation))) {
            throw new UpgradeException(__('upgrade.errors.confirmation_mismatch', [
                'phrase' => $this->confirmationPhrase(),
            ]));
        }
    }

    /**
     * Restore the file tree captured by a previous run's snapshot
     * (F-6.2-004 rollback). Returns false when the snapshot is gone — the
     * upgrade_runs row still names it, which is what the manual recovery
     * procedure needs.
     */
    public function rollback(UpgradeRun $run): bool
    {
        $snapshot = (string) ($run->snapshot_path ?? '');

        if ($snapshot === '' || ! is_dir($snapshot)) {
            return false;
        }

        // An out-of-band rollback is about a run that already finished, so the
        // persisted ledger on the row is the only correct source.
        $this->createdFiles = [];

        $restored = $this->restore($run, $snapshot);

        if ($restored) {
            $run->forceFill([
                'status' => UpgradeRun::STATUS_ROLLED_BACK,
                'notes' => trim((string) $run->notes."\n".__('upgrade.notes.manual_rollback')),
                'finished_at' => Carbon::now(),
            ])->save();
        }

        return $restored;
    }

    // ------------------------------------------------------------------ run

    private function execute(ReleaseInfo $release, ?User $actor, bool $dryRun): UpgradeRun
    {
        // Resolve (and refuse to resolve unsafely) BEFORE a row exists, so a
        // misconfigured target never even produces a history entry.
        $targetPath = $this->target->assertSafe();

        $run = UpgradeRun::query()->create([
            'user_id' => $actor?->getKey(),
            'from_version' => CoreVersion::current(),
            'to_version' => $release->version,
            'channel' => (string) config('emp-upgrade.release.channel', 'stable'),
            'status' => UpgradeRun::STATUS_PENDING,
            'dry_run' => $dryRun,
            'checksum' => $release->checksum,
            'started_at' => Carbon::now(),
            'steps' => [],
        ]);

        $archivePath = null;
        $archive = null;
        $snapshot = null;
        $shielded = false;
        $this->createdFiles = [];

        try {
            // --- 1. pre-flight (F-6.2-003) -----------------------------------
            $this->stepStart($run, UpgradeRun::STEP_PREFLIGHT);
            $checks = $this->preflight->run($release);
            $run->checks = $checks;
            $blockers = $this->preflight->blockers($checks);

            if ($blockers !== []) {
                $this->recordStep($run, UpgradeRun::STEP_PREFLIGHT, 'failed', implode(' ', $blockers));

                return $this->finish($run, UpgradeRun::STATUS_BLOCKED, implode("\n", $blockers));
            }

            $this->recordStep($run, UpgradeRun::STEP_PREFLIGHT, 'ok', __('upgrade.steps.preflight_ok', [
                'count' => count($checks),
            ]));

            $run->forceFill(['status' => UpgradeRun::STATUS_RUNNING])->save();

            // --- 2. download --------------------------------------------------
            $this->stepStart($run, UpgradeRun::STEP_DOWNLOAD);
            $archivePath = $this->download($release);
            $bytes = (int) @filesize($archivePath);
            $run->forceFill(['archive_bytes' => $bytes])->save();
            $this->recordStep($run, UpgradeRun::STEP_DOWNLOAD, 'ok', __('upgrade.steps.download_detail', [
                'bytes' => $bytes,
            ]));

            // --- 3. verify checksum + archive safety --------------------------
            $this->stepStart($run, UpgradeRun::STEP_VERIFY);
            $this->verifyChecksum($release, $archivePath);
            $archive = ReleaseArchive::open($archivePath);
            $archive->assertMatches($release->version);
            $plan = $this->plan($archive);
            $run->forceFill(['file_plan' => $plan])->save();
            $this->recordStep($run, UpgradeRun::STEP_VERIFY, 'ok', __('upgrade.steps.verify_detail', [
                'algo' => $release->checksumAlgo,
                'files' => count($archive->entries()),
                'protected' => count($plan['protected']),
            ]));

            if ($dryRun) {
                foreach ([
                    UpgradeRun::STEP_BACKUP,
                    UpgradeRun::STEP_MAINTENANCE_ON,
                    UpgradeRun::STEP_APPLY,
                    UpgradeRun::STEP_MIGRATE,
                    UpgradeRun::STEP_CACHES,
                    UpgradeRun::STEP_MAINTENANCE_OFF,
                ] as $step) {
                    $this->recordStep($run, $step, 'skipped', __('upgrade.steps.dry_run_skipped'));
                }

                $archive->close();
                $archive = null;
                $this->cleanup($run, $archivePath);

                return $this->finish($run, UpgradeRun::STATUS_SUCCESS, null, __('upgrade.notes.dry_run', [
                    'add' => count($plan['add']),
                    'replace' => count($plan['replace']),
                    'protected' => count($plan['protected']),
                ]));
            }

            // --- 4. pre-upgrade backup (F-6.2-004) ----------------------------
            $this->stepStart($run, UpgradeRun::STEP_BACKUP);
            $artefacts = $this->backup->run();
            $run->forceFill([
                'backup_database' => $artefacts['database'],
                'backup_uploads' => $artefacts['uploads'],
            ])->save();
            $this->recordStep($run, UpgradeRun::STEP_BACKUP, 'ok', $artefacts['detail']);

            // --- 5. maintenance mode (F-6.2-005) ------------------------------
            $this->stepStart($run, UpgradeRun::STEP_MAINTENANCE_ON);
            $shielded = $this->maintenance->down();
            $this->recordStep(
                $run,
                UpgradeRun::STEP_MAINTENANCE_ON,
                $shielded ? 'ok' : 'warning',
                $shielded ? __('upgrade.steps.maintenance_on') : __('upgrade.steps.maintenance_unavailable'),
            );

            // --- 6. apply files (F-6.2-002 / F-6.2-006) -----------------------
            $this->stepStart($run, UpgradeRun::STEP_APPLY);
            $snapshot = $this->snapshotPath($run);
            $run->forceFill(['snapshot_path' => $snapshot])->save();
            $applied = $this->applyFiles($run, $archive, $plan, $snapshot);
            $this->recordStep($run, UpgradeRun::STEP_APPLY, 'ok', __('upgrade.steps.apply_detail', [
                'added' => $applied['added'],
                'replaced' => $applied['replaced'],
                'protected' => count($plan['protected']),
            ]));

            $archive->close();
            $archive = null;

            // --- 7. migrate ---------------------------------------------------
            $this->stepStart($run, UpgradeRun::STEP_MIGRATE);
            $this->recordStep($run, UpgradeRun::STEP_MIGRATE, 'ok', $this->migrate());

            // --- 8. clear caches ----------------------------------------------
            $this->stepStart($run, UpgradeRun::STEP_CACHES);
            $this->recordStep($run, UpgradeRun::STEP_CACHES, 'ok', $this->clearCaches());

            // --- 9. maintenance off -------------------------------------------
            $this->stepStart($run, UpgradeRun::STEP_MAINTENANCE_OFF);
            $up = $this->maintenance->up();
            $shielded = false;
            $this->recordStep(
                $run,
                UpgradeRun::STEP_MAINTENANCE_OFF,
                $up ? 'ok' : 'warning',
                $up ? __('upgrade.steps.maintenance_off') : __('upgrade.steps.maintenance_stuck'),
            );

            // --- 10. cleanup ---------------------------------------------------
            $this->cleanup($run, $archivePath);

            return $this->finish($run, UpgradeRun::STATUS_SUCCESS, null, $this->versionNote($release));
        } catch (Throwable $exception) {
            $archive?->close();

            return $this->fail($run, $exception, $snapshot, $shielded, $archivePath);
        }
    }

    // -------------------------------------------------------------- steps

    private function download(ReleaseInfo $release): string
    {
        $url = $release->downloadUrl;

        if ($url === null) {
            throw UpgradeException::atStep(UpgradeRun::STEP_DOWNLOAD, __('upgrade.errors.no_download_url'));
        }

        $directory = $this->target->workPath();

        if (! Files::accessible($directory)) {
            throw UpgradeException::atStep(
                UpgradeRun::STEP_DOWNLOAD,
                __('ops.files.open_basedir_denied', ['path' => $directory])
            );
        }

        File::ensureDirectoryExists($directory);

        $path = $directory.'/release-'.$release->version.'.zip';
        @unlink($path);

        $maxBytes = (int) config('emp-upgrade.limits.max_download_mb', 250) * 1024 * 1024;

        try {
            $response = Http::timeout((int) config('emp-upgrade.release.timeout', 15) * 20)
                ->withOptions(['sink' => $path])
                ->get($url);
        } catch (Throwable $exception) {
            @unlink($path);

            throw UpgradeException::atStep(UpgradeRun::STEP_DOWNLOAD, __('upgrade.errors.download_failed', [
                'error' => $exception->getMessage(),
            ]));
        }

        if ($response->failed()) {
            @unlink($path);

            throw UpgradeException::atStep(UpgradeRun::STEP_DOWNLOAD, __('upgrade.errors.download_status', [
                'status' => $response->status(),
            ]));
        }

        // The mock/stream handler may buffer rather than sink; either way the
        // bytes must end up on disk before anything else looks at them.
        if (! is_file($path) || (int) filesize($path) === 0) {
            File::put($path, $response->body());
        }

        $bytes = (int) @filesize($path);

        if ($bytes === 0) {
            @unlink($path);

            throw UpgradeException::atStep(UpgradeRun::STEP_DOWNLOAD, __('upgrade.errors.download_empty'));
        }

        if ($maxBytes > 0 && $bytes > $maxBytes) {
            @unlink($path);

            throw UpgradeException::atStep(UpgradeRun::STEP_DOWNLOAD, __('upgrade.errors.download_too_large', [
                'max' => (int) config('emp-upgrade.limits.max_download_mb', 250),
            ]));
        }

        return $path;
    }

    private function verifyChecksum(ReleaseInfo $release, string $path): void
    {
        $expected = (string) $release->checksum;
        $actual = (string) (hash_file($release->checksumAlgo, $path) ?: '');

        if ($actual === '' || ! hash_equals($expected, strtolower($actual))) {
            throw UpgradeException::atStep(UpgradeRun::STEP_VERIFY, __('upgrade.errors.checksum_mismatch', [
                'expected' => $expected,
                'actual' => $actual === '' ? '—' : $actual,
            ]));
        }
    }

    /**
     * What WOULD happen, computed read-only. `protected` is the F-6.2-006
     * evidence trail: every path the package tried to touch and was refused.
     *
     * @return array{add: list<string>, replace: list<string>, protected: list<string>, bytes: int}
     */
    public function plan(ReleaseArchive $archive): array
    {
        $add = [];
        $replace = [];
        $blocked = [];

        foreach ($archive->entries() as $relative) {
            if ($this->target->isProtected($relative)) {
                $blocked[] = $relative;

                continue;
            }

            $destination = $this->target->destinationFor($relative);

            if ($destination === null) {
                throw UpgradeException::atStep(
                    UpgradeRun::STEP_VERIFY,
                    __('upgrade.errors.archive_unsafe_entry', ['entry' => $relative])
                );
            }

            is_file($destination) ? $replace[] = $relative : $add[] = $relative;
        }

        return [
            'add' => $add,
            'replace' => $replace,
            'protected' => $blocked,
            'bytes' => $archive->totalBytes(),
        ];
    }

    /**
     * @param  array{add: list<string>, replace: list<string>, protected: list<string>, bytes: int}  $plan
     * @return array{added: int, replaced: int}
     */
    private function applyFiles(UpgradeRun $run, ReleaseArchive $archive, array $plan, string $snapshot): array
    {
        File::ensureDirectoryExists($snapshot);

        $added = 0;
        $replaced = 0;

        foreach (array_merge($plan['replace'], $plan['add']) as $relative) {
            // Re-check on the write path: the plan was computed earlier and
            // the protected list is the invariant, not the plan.
            if ($this->target->isProtected($relative)) {
                continue;
            }

            $destination = $this->target->destinationFor($relative);

            if ($destination === null) {
                throw UpgradeException::atStep(
                    UpgradeRun::STEP_APPLY,
                    __('upgrade.errors.archive_unsafe_entry', ['entry' => $relative])
                );
            }

            $contents = $archive->read($relative);

            if ($contents === null) {
                throw UpgradeException::atStep(
                    UpgradeRun::STEP_APPLY,
                    __('upgrade.errors.entry_unreadable', ['entry' => $relative])
                );
            }

            if (is_file($destination)) {
                $snapshotFile = $snapshot.'/'.$relative;
                File::ensureDirectoryExists(dirname($snapshotFile));

                if (! @copy($destination, $snapshotFile)) {
                    throw UpgradeException::atStep(
                        UpgradeRun::STEP_APPLY,
                        __('upgrade.errors.snapshot_failed', ['entry' => $relative])
                    );
                }

                $replaced++;
            } else {
                // Recorded BEFORE the write: a process killed between these two
                // lines must still leave a ledger entry, or rollback would
                // orphan the file it was about to create.
                $this->createdFiles[] = $relative;
                $added++;
            }

            File::ensureDirectoryExists(dirname($destination));

            if (File::put($destination, $contents) === false) {
                throw UpgradeException::atStep(
                    UpgradeRun::STEP_APPLY,
                    __('upgrade.errors.write_failed', ['entry' => $relative])
                );
            }

            // Flushed periodically so a run killed mid-apply leaves a usable
            // ledger on the row for a MANUAL rollback later. The in-memory
            // list is what the automatic rollback in this process uses.
            if (count($this->createdFiles) % 250 === 0) {
                $run->forceFill(['created_files' => $this->createdFiles])->save();
            }
        }

        $run->forceFill(['created_files' => $this->createdFiles])->save();

        return ['added' => $added, 'replaced' => $replaced];
    }

    private function migrate(): string
    {
        $exit = Artisan::call('migrate', ['--force' => true]);

        if ($exit !== 0) {
            throw UpgradeException::atStep(UpgradeRun::STEP_MIGRATE, __('upgrade.errors.migrate_failed', [
                'output' => trim(Artisan::output()),
            ]));
        }

        return __('upgrade.steps.migrate_detail', ['output' => trim(Artisan::output()) ?: '—']);
    }

    private function clearCaches(): string
    {
        $ran = [];

        foreach ((array) config('emp-upgrade.post_upgrade_commands', []) as $command) {
            $name = (string) $command;

            try {
                Artisan::call($name);
                $ran[] = $name;
            } catch (Throwable $exception) {
                // D-87: filament:assets matters, but a cache command that does
                // not exist on this install must not undo a good upgrade.
                Log::warning('upgrade.cache_command_failed', [
                    'command' => $name,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return __('upgrade.steps.caches_detail', ['commands' => implode(', ', $ran) ?: '—']);
    }

    private function cleanup(UpgradeRun $run, ?string $archivePath): void
    {
        $this->stepStart($run, UpgradeRun::STEP_CLEANUP);

        if ($archivePath !== null) {
            @unlink($archivePath);
        }

        // The rollback snapshot is deliberately KEPT after a successful
        // upgrade — it is the only way back to the previous code, and
        // docs/upgrades.md tells the operator where it is and when to delete.
        $this->recordStep($run, UpgradeRun::STEP_CLEANUP, 'ok', __('upgrade.steps.cleanup_detail', [
            'snapshot' => (string) ($run->snapshot_path ?? '—'),
        ]));
    }

    // ------------------------------------------------------------- failure

    private function fail(
        UpgradeRun $run,
        Throwable $exception,
        ?string $snapshot,
        bool $shielded,
        ?string $archivePath,
    ): UpgradeRun {
        $step = $exception instanceof UpgradeException
            ? ($exception->step ?? (string) $run->current_step)
            : (string) $run->current_step;

        $this->recordStep($run, $step ?: UpgradeRun::STEP_PREFLIGHT, 'failed', $exception->getMessage());

        $notes = [];
        $status = UpgradeRun::STATUS_FAILED;

        if ($snapshot !== null && is_dir($snapshot)) {
            if ($this->restore($run, $snapshot)) {
                $status = UpgradeRun::STATUS_ROLLED_BACK;
                $notes[] = __('upgrade.notes.files_restored');
            } else {
                $notes[] = __('upgrade.notes.restore_incomplete', ['path' => $snapshot]);
            }
        } else {
            // Nothing was written yet, so there is nothing to undo.
            $status = UpgradeRun::STATUS_ROLLED_BACK;
            $notes[] = __('upgrade.notes.no_files_written');
        }

        if ($step === UpgradeRun::STEP_MIGRATE || $step === UpgradeRun::STEP_CACHES) {
            $notes[] = __('upgrade.notes.database_may_be_migrated', [
                'backup' => (string) ($run->backup_database ?? '—'),
            ]);
        }

        if ($shielded && ! $this->maintenance->up()) {
            $notes[] = __('upgrade.notes.maintenance_stuck');
        }

        if ($archivePath !== null) {
            @unlink($archivePath);
        }

        Log::error('upgrade.failed', [
            'run' => $run->uid,
            'step' => $step,
            'error' => $exception->getMessage(),
        ]);

        return $this->finish($run, $status, $exception->getMessage(), implode("\n", $notes));
    }

    /** Put every file this run touched back exactly as it was. */
    private function restore(UpgradeRun $run, string $snapshot): bool
    {
        $ok = true;

        // The in-memory ledger wins while the run is still in this process —
        // the persisted column is only flushed every 250 files, so a failure
        // mid-apply would otherwise leave the most recent creations orphaned.
        $created = $this->createdFiles !== []
            ? $this->createdFiles
            : (array) ($run->created_files ?? []);

        foreach ($created as $relative) {
            $destination = $this->target->destinationFor((string) $relative);

            if ($destination !== null && is_file($destination) && ! @unlink($destination)) {
                $ok = false;
            }
        }

        if (! is_dir($snapshot)) {
            return $ok;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($snapshot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace(
                '\\',
                '/',
                substr($file->getPathname(), strlen($snapshot))
            ), '/');

            $destination = $this->target->destinationFor($relative);

            if ($destination === null) {
                $ok = false;

                continue;
            }

            File::ensureDirectoryExists(dirname($destination));

            if (! @copy($file->getPathname(), $destination)) {
                $ok = false;
            }
        }

        return $ok;
    }

    // -------------------------------------------------------------- record

    private function snapshotPath(UpgradeRun $run): string
    {
        return $this->target->workPath().'/rollback/'.$run->uid;
    }

    private function stepStart(UpgradeRun $run, string $step): void
    {
        $run->forceFill(['current_step' => $step])->save();
    }

    private function recordStep(UpgradeRun $run, string $step, string $status, string $detail): void
    {
        $steps = (array) ($run->steps ?? []);
        $steps[] = ['key' => $step, 'status' => $status, 'detail' => $detail, 'at' => Carbon::now()->toIso8601String()];

        $run->forceFill(['steps' => array_values($steps)])->save();
    }

    private function finish(UpgradeRun $run, string $status, ?string $error = null, ?string $notes = null): UpgradeRun
    {
        $run->forceFill([
            'status' => $status,
            'error' => $error,
            'notes' => $notes === null || trim($notes) === '' ? $run->notes : trim($notes),
            'current_step' => null,
            'finished_at' => Carbon::now(),
        ])->save();

        return $run->refresh();
    }

    /**
     * Honest reporting of the version bump. `config('emp.core_version')` is
     * the canonical version (D-95) and it is read from the shipped
     * `config/emp.php` — which the release archive replaces. But an install
     * that pins `EMP_CORE_VERSION` in `.env` overrides that, and `.env` is a
     * protected path this upgrade will never write. So the run states plainly
     * whether the running version actually moved.
     */
    private function versionNote(ReleaseInfo $release): string
    {
        // config/ is cached in the running process; re-read from the file the
        // upgrade just wrote, when it exists in the target.
        $current = CoreVersion::current();

        if (env('EMP_CORE_VERSION') !== null) {
            return __('upgrade.notes.version_pinned', [
                'release' => $release->version,
                'current' => $current,
            ]);
        }

        return __('upgrade.notes.version_applied', ['release' => $release->version]);
    }
}
