<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Automation;
use App\Models\Campaign;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\SendingDomain;
use App\Models\Subscriber;
use App\Models\SubUser;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * §2.15.7 F-2.15.7-001 — turns model lifecycle events on the tenant's own
 * data into activity rows (WP-39).
 *
 * One observer registered against several models (see
 * routes/modules/team.php) rather than one class per model: the mapping is
 * pure data, and a per-model observer would be seven near-identical files
 * to keep in sync.
 *
 * Updates are only recorded when a MEANINGFUL attribute changed —
 * `updated_at`-only touches, counter rollups and last_*_at stamps are the
 * bulk of writes on these tables and would bury the real history.
 */
class ActivityLogObserver
{
    /** Models this observer is attached to → their action prefix. */
    public const SUBJECTS = [
        EmailList::class => 'list',
        Subscriber::class => 'subscriber',
        Campaign::class => 'campaign',
        EmailTemplate::class => 'template',
        Automation::class => 'automation',
        DeliveryServer::class => 'server',
        SendingDomain::class => 'domain',
        SubUser::class => 'team_member',
    ];

    /** Placeholder written in place of a secret's before/after value. */
    public const REDACTED = '__redacted__';

    /** Values longer than this are truncated so one HTML body cannot bloat the log. */
    private const MAX_VALUE_LENGTH = 500;

    /**
     * Any attribute whose name contains one of these is a credential and its
     * value is never written to the audit trail.
     *
     * @var list<string>
     */
    private const SECRET_FRAGMENTS = [
        'password', 'secret', 'token', 'api_key', 'private_key', 'credential', 'signature',
    ];

    /**
     * Attributes whose change is noise, never history.
     *
     * @var list<string>
     */
    private const IGNORED = [
        'updated_at',
        'created_at',
        'last_open_at',
        'last_click_at',
        'last_bounce_at',
        'last_login_at',
        'last_used_at',
        'bounces_count',
        'complaints_count',
        'subscribers_count',
        'remember_token',
        'next_run_at',
        'last_run_at',
    ];

    public function __construct(private readonly ActivityRecorder $recorder) {}

    public function created(Model $model): void
    {
        $this->write($model, 'created');
    }

    public function updated(Model $model): void
    {
        $changed = array_keys($model->getChanges());
        $meaningful = array_values(array_diff($changed, self::IGNORED));

        if ($meaningful === []) {
            return;
        }

        // WP-40 §3.27 F-3.27-011: record what each field changed FROM and TO,
        // not just its name, so the audit viewer can render a real diff.
        $old = [];
        $new = [];

        foreach ($meaningful as $attribute) {
            $old[$attribute] = $this->presentable($attribute, $model->getOriginal($attribute));
            $new[$attribute] = $this->presentable($attribute, $model->getAttribute($attribute));
        }

        $this->write($model, 'updated', ['changed' => $meaningful], $old, $new);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted');
    }

    public function restored(Model $model): void
    {
        $this->write($model, 'restored');
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function write(Model $model, string $verb, array $properties = [], ?array $old = null, ?array $new = null): void
    {
        $prefix = self::SUBJECTS[$model::class] ?? null;

        if ($prefix === null) {
            return;
        }

        $this->recorder->record(
            $prefix.'.'.$verb,
            $model,
            properties: $properties,
            oldValues: $old,
            newValues: $new,
        );
    }

    /**
     * An audit trail must never become a credential leak, and it must stay
     * readable: secrets are replaced with a marker, objects are stringified,
     * and long text is truncated. The marker is a lang key so the viewer can
     * show it in the operator's own language.
     */
    private function presentable(string $attribute, mixed $value): mixed
    {
        if ($this->isSecret($attribute)) {
            return $value === null || $value === '' ? null : self::REDACTED;
        }

        if ($value === null || is_scalar($value)) {
            return is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH
                ? mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…'
                : $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        $encoded = json_encode($value);

        return $encoded === false || mb_strlen($encoded) > self::MAX_VALUE_LENGTH
            ? mb_substr((string) $encoded, 0, self::MAX_VALUE_LENGTH).'…'
            : $encoded;
    }

    private function isSecret(string $attribute): bool
    {
        $attribute = mb_strtolower($attribute);

        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($attribute, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
