<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Customer;
use App\Models\NotificationPreference;
use App\Models\SubUser;
use App\Support\Billing\Monetization;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * §2.15.3 F-2.15.3-001/002 — per-event × per-channel notification
 * preferences (WP-39).
 *
 * Storage stores DEVIATIONS only: no row means "use NotificationEvent's
 * default". That keeps the table tiny and means a newly added event is
 * live for every existing account with no backfill.
 *
 * Resolution order for a given (event, channel):
 *   1. the acting team member's personal row, when one exists;
 *   2. the account-wide row set by the owner;
 *   3. the event's own default.
 *
 * Results are memoised per request — a campaign-finished sweep asks the
 * same question once per customer, not once per recipient.
 */
class NotificationPreferences
{
    /** @var array<string, bool> */
    private array $memo = [];

    /**
     * The single question every sender asks before delivering.
     *
     * Billing events additionally disappear when monetization is switched
     * off (F-3.25.14-001) — there is no billing to be notified about.
     */
    public function allows(
        Customer $customer,
        NotificationEvent $event,
        NotificationChannel $channel,
        ?SubUser $subUser = null,
    ): bool {
        if ($event->isBilling() && ! (class_exists(Monetization::class) && Monetization::enabled())) {
            return false;
        }

        $key = implode(':', [
            (string) $customer->id,
            $subUser?->id ?? '-',
            $event->value,
            $channel->value,
        ]);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->resolve($customer, $event, $channel, $subUser);
    }

    /**
     * Full matrix for the preferences screen, defaults folded in.
     *
     * @return array<string, array<string, bool>> event => channel => enabled
     */
    public function matrix(Customer $customer, ?SubUser $subUser = null): array
    {
        $stored = $this->storedRows($customer, $subUser);
        $matrix = [];

        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $matrix[$event->value][$channel->value] = $stored[$event->value][$channel->value]
                    ?? $event->defaultFor($channel);
            }
        }

        return $matrix;
    }

    /**
     * Persist a full matrix. Values equal to the event default delete their
     * row rather than storing a redundant one, so the table only ever holds
     * genuine deviations.
     *
     * @param  array<string, array<string, bool>>  $matrix
     */
    public function save(Customer $customer, array $matrix, ?SubUser $subUser = null): void
    {
        DB::transaction(function () use ($customer, $matrix, $subUser): void {
            foreach (NotificationEvent::cases() as $event) {
                foreach (NotificationChannel::cases() as $channel) {
                    $enabled = (bool) ($matrix[$event->value][$channel->value] ?? $event->defaultFor($channel));

                    $criteria = [
                        'customer_id' => $customer->id,
                        'sub_user_id' => $subUser?->id,
                        'event' => $event->value,
                        'channel' => $channel->value,
                    ];

                    if ($enabled === $event->defaultFor($channel)) {
                        NotificationPreference::withoutTenancy()->where($criteria)->delete();

                        continue;
                    }

                    NotificationPreference::withoutTenancy()->updateOrCreate($criteria, ['enabled' => $enabled]);
                }
            }
        });

        $this->flush();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    private function resolve(
        Customer $customer,
        NotificationEvent $event,
        NotificationChannel $channel,
        ?SubUser $subUser,
    ): bool {
        $rows = $this->storedRows($customer, $subUser);

        return $rows[$event->value][$channel->value] ?? $event->defaultFor($channel);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function storedRows(Customer $customer, ?SubUser $subUser): array
    {
        try {
            $query = NotificationPreference::withoutTenancy()->where('customer_id', $customer->id);

            $query = $subUser !== null
                ? $query->where(fn ($q) => $q->whereNull('sub_user_id')->orWhere('sub_user_id', $subUser->id))
                // Account-wide view: personal member overrides must not leak
                // into the owner's own resolution.
                : $query->whereNull('sub_user_id');

            // Personal rows are ordered last so they overwrite account rows.
            $rows = $query->orderByRaw('CASE WHEN sub_user_id IS NULL THEN 0 ELSE 1 END')->get();
        } catch (Throwable) {
            return [];
        }

        $matrix = [];

        foreach ($rows as $row) {
            $matrix[$row->event->value][$row->channel->value] = (bool) $row->enabled;
        }

        return $matrix;
    }
}
