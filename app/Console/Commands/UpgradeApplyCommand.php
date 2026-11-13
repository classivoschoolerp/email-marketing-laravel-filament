<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UpgradeRun;
use App\Services\Upgrade\ReleaseChecker;
use App\Services\Upgrade\UpgradeException;
use App\Services\Upgrade\UpgradeRunner;
use Illuminate\Console\Command;

/**
 * `upgrade:apply` — the CLI half of the one-click upgrade (spec §6.2
 * F-6.2-002, WP-41).
 *
 * Never scheduled, and never interactive-confirmation-free: `--confirm` must
 * carry the exact phrase from `emp-upgrade.confirmation_phrase`, which is the
 * same guard the admin page enforces. An operator who wants to see what would
 * happen runs `--dry-run`, which downloads and verifies the package and
 * prints the file plan without writing anything.
 */
class UpgradeApplyCommand extends Command
{
    protected $signature = 'upgrade:apply {--confirm= : The confirmation phrase; required unless --dry-run}
                                          {--dry-run : Verify and report the file plan without changing anything}
                                          {--force : Bypass the cached release feed}';

    protected $description = 'Download, verify and apply the latest release (destructive — see docs/upgrades.md).';

    public function handle(ReleaseChecker $checker, UpgradeRunner $runner): int
    {
        $release = $checker->latest(force: (bool) $this->option('force'));

        if ($release === null) {
            $this->warn(__('upgrade.errors.no_release'));

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->line(__('upgrade.console.dry_run_header'));
            $run = $runner->dryRun($release);
        } else {
            try {
                $runner->assertConfirmed((string) $this->option('confirm'));
            } catch (UpgradeException $exception) {
                $this->error(__('upgrade.console.confirm_required', [
                    'phrase' => $runner->confirmationPhrase(),
                ]));

                return self::FAILURE;
            }

            $run = $runner->apply($release, (string) $this->option('confirm'));
        }

        $this->renderRun($run);

        $this->line(__('upgrade.console.result', ['uid' => $run->uid, 'status' => $run->status]));

        return $run->status === UpgradeRun::STATUS_SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function renderRun(UpgradeRun $run): void
    {
        if ($run->status === UpgradeRun::STATUS_BLOCKED) {
            $this->error(__('upgrade.console.blocked'));

            foreach ($run->checkRows() as $check) {
                if ($check['status'] === 'fail' && $check['blocking']) {
                    $this->line('  - '.__('upgrade.checks.'.$check['key'].'.label').': '.$check['detail']);
                }
            }

            return;
        }

        $rows = [];

        foreach ($run->stepRows() as $step) {
            $rows[] = [
                __('upgrade.steps.labels.'.$step['key']),
                __('upgrade.steps.status.'.$step['status']),
                $step['detail'],
            ];
        }

        $this->table(['Step', 'Status', 'Detail'], $rows);

        $plan = (array) ($run->file_plan ?? []);

        if ($plan !== []) {
            $this->line(__('upgrade.steps.apply_detail', [
                'added' => count((array) ($plan['add'] ?? [])),
                'replaced' => count((array) ($plan['replace'] ?? [])),
                'protected' => count((array) ($plan['protected'] ?? [])),
            ]));

            foreach ((array) ($plan['protected'] ?? []) as $path) {
                $this->line('  skipped (protected): '.(string) $path);
            }
        }

        if (($run->notes ?? '') !== '') {
            $this->newLine();
            $this->line((string) $run->notes);
        }

        if (($run->error ?? '') !== '') {
            $this->error((string) $run->error);
        }
    }
}
