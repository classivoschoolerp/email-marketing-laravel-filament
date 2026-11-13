<?php

declare(strict_types=1);

namespace App\Listeners\Security;

use App\Models\PasswordHistory;
use App\Services\Settings\SettingsReader;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Password-change listener (spec §3.25.15 F-3.25.15-003, WP-22).
 *
 * Bound to the `saved` model event of both authenticatables by
 * {@see \App\Providers\ComplianceServiceProvider}, so EVERY password write
 * path is covered — customer Profile, admin Security, password resets, the
 * admin CustomerResource/UserResource forms and any future one — without
 * those call sites knowing this exists.
 *
 * Two effects per change: append the (already hashed) value to
 * password_histories for reuse prevention, and stamp password_changed_at for
 * expiry. History is trimmed to the configured depth on write.
 */
final class RecordPasswordChange
{
    public function handle(Model $model): void
    {
        // A fresh account's hash is history too — it is the password the next
        // change must not reuse. `wasChanged()` is empty right after an
        // insert, so creation is detected separately.
        if (! $model->wasRecentlyCreated && ! $model->wasChanged('password')) {
            return;
        }

        $hash = (string) ($model->getAttribute('password') ?? '');

        if ($hash === '') {
            return;
        }

        PasswordHistory::query()->create([
            'authenticatable_type' => $model->getMorphClass(),
            'authenticatable_id' => $model->getKey(),
            'password_hash' => $hash,
            'created_at' => now(),
        ]);

        $this->trim($model);
        $this->stampChangedAt($model);
    }

    /**
     * Keep only the newest `password_reuse_prevention` rows (plus a small
     * head-room of 1) so the table stays bounded even when the depth is
     * lowered later.
     */
    private function trim(Model $model): void
    {
        try {
            $depth = max(1, SettingsReader::security()->passwordReusePrevention()) + 1;
        } catch (Throwable) {
            $depth = 5;
        }

        $keep = PasswordHistory::query()
            ->where('authenticatable_type', $model->getMorphClass())
            ->where('authenticatable_id', $model->getKey())
            ->orderByDesc('id')
            ->limit($depth)
            ->pluck('id')
            ->all();

        if ($keep === []) {
            return;
        }

        PasswordHistory::query()
            ->where('authenticatable_type', $model->getMorphClass())
            ->where('authenticatable_id', $model->getKey())
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /** Quiet write: stamping the timestamp must not re-enter this listener. */
    private function stampChangedAt(Model $model): void
    {
        if (! in_array('password_changed_at', $this->columns($model), true)) {
            return;
        }

        $model->forceFill(['password_changed_at' => now()])->saveQuietly();
    }

    /**
     * Column listing, memoised per table: this runs on every account create
     * and password change, and a schema query each time would be wasteful.
     *
     * @var array<string, array<int, string>>
     */
    private static array $columnCache = [];

    /** @return array<int, string> */
    private function columns(Model $model): array
    {
        $table = $model->getTable();

        if (isset(self::$columnCache[$table])) {
            return self::$columnCache[$table];
        }

        try {
            return self::$columnCache[$table] = $model->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($table);
        } catch (Throwable) {
            return [];
        }
    }
}
