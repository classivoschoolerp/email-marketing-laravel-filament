<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationNodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Published graph node (spec §2.4.2). `edges` = {branchKey: childNodeUid}.
 * Not tenant-scoped directly — always reached through its Automation
 * (which is), mirroring SuppressionListEmail.
 */
class AutomationNode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AutomationNodeType::class,
            'config' => 'array',
            'edges' => 'array',
            'sort' => 'integer',
            'entered_count' => 'integer',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class)->withoutGlobalScopes();
    }

    /** Child node uid for a branch key, if wired. */
    public function edge(string $branch): ?string
    {
        $edges = (array) ($this->edges ?? []);
        $target = $edges[$branch] ?? null;

        return is_string($target) && $target !== '' ? $target : null;
    }

    /**
     * Effective branch keys: static per type, or enumerated from config for
     * dynamic-branch splits (case:N + default, percentage letters).
     *
     * @return list<string>
     */
    public function branchKeys(): array
    {
        return self::branchKeysFor($this->type, (array) ($this->config ?? []));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function branchKeysFor(AutomationNodeType $type, array $config): array
    {
        if ($type === AutomationNodeType::LogicSplit) {
            $keys = [];

            foreach (array_keys(array_values((array) ($config['cases'] ?? []))) as $index) {
                $keys[] = 'case:'.$index;
            }

            $keys[] = 'default';

            return $keys;
        }

        if ($type === AutomationNodeType::LogicPercentage) {
            $count = max(2, count((array) ($config['variants'] ?? [])));

            return array_map(
                fn (int $index): string => chr(ord('a') + $index),
                range(0, min($count, 26) - 1),
            );
        }

        return $type->branches();
    }

    /** Config value helper. */
    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
