<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * §2.15.7 one account-activity row (WP-39). Immutable: created_at only, no
 * updates, no soft deletes. Tenant-scoped like every other tenant-owned
 * model (D-05) so a customer can only ever read its own history.
 */
class ActivityLog extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            // WP-40 §3.27 F-3.27-011: the before/after diff. Both carry the
            // same key set; a row written before WP-40 has NULL in both and
            // the viewer says so instead of rendering an empty diff.
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Field-by-field diff for the §3.27 audit viewer (F-3.27-011).
     *
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    public function diff(): array
    {
        $old = (array) ($this->old_values ?? []);
        $new = (array) ($this->new_values ?? []);

        $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
        sort($fields);

        return array_map(static fn (string $field): array => [
            'field' => $field,
            'old' => $old[$field] ?? null,
            'new' => $new[$field] ?? null,
        ], $fields);
    }

    public function hasDiff(): bool
    {
        return $this->old_values !== null || $this->new_values !== null;
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** F-2.15.7-002 — filter by the team member (or owner) who acted. */
    public function scopeByActor(Builder $query, string $actorType, int|string $actorId): Builder
    {
        return $query->where('actor_type', $actorType)->where('actor_id', $actorId);
    }

    /**
     * Human label for the action key, falling back to the raw key.
     *
     * The dot in the action ('list.created') is flattened to an underscore
     * for the lookup: Laravel's translator treats dots as nesting, so
     * `activity.actions.list.created` would look for a `list` sub-array
     * that does not exist and silently return the key.
     */
    public function actionLabel(): string
    {
        return self::labelFor((string) $this->action);
    }

    public static function labelFor(string $action): string
    {
        $key = 'activity.actions.'.str_replace('.', '_', $action);
        $label = __($key);

        return $label === $key ? $action : (string) $label;
    }

    /** Who did it — the denormalised name survives actor deletion. */
    public function actorLabel(): string
    {
        return (string) ($this->actor_name ?: __('activity.actor_system'));
    }
}
