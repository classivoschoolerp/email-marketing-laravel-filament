<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model over Laravel's failed_jobs table (spec §0.4 F-0.4-003 /
 * §3.27 F-3.27-012, WP-17) for the admin failed-queue-jobs UI. Rows are
 * written by the queue worker; the UI only inspects, retries (queue:retry)
 * and deletes. Global — queue infrastructure has no tenant.
 */
class FailedJob extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }

    /** Job display name from the payload (F-3.27-012). */
    public function jobName(): string
    {
        $payload = json_decode((string) $this->payload, true);

        return is_array($payload)
            ? (string) ($payload['displayName'] ?? ($payload['job'] ?? 'unknown'))
            : 'unknown';
    }

    /** First line of the stored exception. */
    public function exceptionSummary(): string
    {
        return (string) strtok((string) $this->exception, "\n");
    }

    /** Pretty-printed payload for the inspect modal. */
    public function prettyPayload(): string
    {
        $payload = json_decode((string) $this->payload, true);

        return is_array($payload)
            ? (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : (string) $this->payload;
    }
}
