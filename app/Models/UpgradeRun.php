<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One recorded upgrade attempt (spec §6.2 F-6.2-007, WP-41).
 *
 * Install-wide by design — there is no customer_id and no global scope: an
 * upgrade is an operator action about the installation, not about a tenant.
 *
 * @property int $id
 * @property string $uid
 * @property int|null $user_id
 * @property string $from_version
 * @property string|null $to_version
 * @property string $status
 * @property bool $dry_run
 * @property string|null $current_step
 * @property array<string, mixed>|null $checks
 * @property array<string, mixed>|null $steps
 * @property array<string, mixed>|null $file_plan
 * @property list<string>|null $created_files
 */
class UpgradeRun extends Model
{
    /** Row created, nothing destructive attempted yet. */
    public const STATUS_PENDING = 'pending';

    /** Steps in flight. A row left here means the run died mid-flight. */
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    /** A step failed and the file tree could NOT be fully restored. */
    public const STATUS_FAILED = 'failed';

    /** A step failed and every applied file was restored. */
    public const STATUS_ROLLED_BACK = 'rolled_back';

    /** Pre-flight refused (F-6.2-003) — nothing was downloaded or written. */
    public const STATUS_BLOCKED = 'blocked';

    public const STEP_PREFLIGHT = 'preflight';

    public const STEP_DOWNLOAD = 'download';

    public const STEP_VERIFY = 'verify_checksum';

    public const STEP_BACKUP = 'backup';

    public const STEP_MAINTENANCE_ON = 'maintenance_on';

    public const STEP_APPLY = 'apply_files';

    public const STEP_MIGRATE = 'migrate';

    public const STEP_CACHES = 'clear_caches';

    public const STEP_MAINTENANCE_OFF = 'maintenance_off';

    public const STEP_CLEANUP = 'cleanup';

    /** Canonical step order — also the order the UI renders them in. */
    public const STEPS = [
        self::STEP_PREFLIGHT,
        self::STEP_DOWNLOAD,
        self::STEP_VERIFY,
        self::STEP_BACKUP,
        self::STEP_MAINTENANCE_ON,
        self::STEP_APPLY,
        self::STEP_MIGRATE,
        self::STEP_CACHES,
        self::STEP_MAINTENANCE_OFF,
        self::STEP_CLEANUP,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'checks' => 'array',
            'steps' => 'array',
            'file_plan' => 'array',
            'created_files' => 'array',
            'archive_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * A run that will never move again on its own. `running` is deliberately
     * NOT terminal — that is the "half-applied" state the recovery section of
     * docs/upgrades.md exists for.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_ROLLED_BACK,
            self::STATUS_BLOCKED,
        ], true);
    }

    /** @return list<array{key: string, status: string, detail: string}> */
    public function stepRows(): array
    {
        $recorded = [];

        foreach ((array) ($this->steps ?? []) as $step) {
            if (is_array($step) && isset($step['key'])) {
                $recorded[(string) $step['key']] = $step;
            }
        }

        $rows = [];

        foreach (self::STEPS as $key) {
            $row = $recorded[$key] ?? null;

            $rows[] = [
                'key' => $key,
                'status' => (string) ($row['status'] ?? 'pending'),
                'detail' => (string) ($row['detail'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @return list<array{key: string, status: string, detail: string, blocking: bool}> */
    public function checkRows(): array
    {
        $rows = [];

        foreach ((array) ($this->checks ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }

            $rows[] = [
                'key' => (string) ($check['key'] ?? ''),
                'status' => (string) ($check['status'] ?? ''),
                'detail' => (string) ($check['detail'] ?? ''),
                'blocking' => (bool) ($check['blocking'] ?? true),
            ];
        }

        return $rows;
    }
}
