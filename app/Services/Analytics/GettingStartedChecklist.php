<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\CampaignStatus;
use App\Enums\DeliveryServerStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\SendingDomain;
use App\Models\Subscriber;

/**
 * §2.1 F-2.1-007 — the "getting started" checklist for new accounts (WP-40).
 *
 * Six steps, in the order an account actually has to do them. Each one is a
 * real query against the tenant's own data, never a stored flag: a checklist
 * that remembers being ticked would keep saying "done" after the customer
 * deleted the list it was about.
 *
 * The widget hides itself once every step is complete — a permanent
 * checklist on an established account is clutter, and dismissing it would
 * need a flag this deliberately does not have.
 */
class GettingStartedChecklist
{
    /** @var list<string> step keys, in order */
    public const STEPS = [
        'create_list',
        'add_subscribers',
        'verify_domain',
        'configure_server',
        'create_campaign',
        'send_campaign',
    ];

    /**
     * @return array{
     *     steps: list<array{key: string, done: bool}>,
     *     completed: int, total: int, percent: int, finished: bool
     * }
     */
    public function for(Customer $customer): array
    {
        $customerId = (int) $customer->id;

        $done = [
            'create_list' => EmailList::withoutTenancy()->where('customer_id', $customerId)->exists(),
            'add_subscribers' => Subscriber::withoutTenancy()->where('customer_id', $customerId)->exists(),
            'verify_domain' => SendingDomain::withoutTenancy()
                ->where('customer_id', $customerId)
                ->where('spf_verified', true)
                ->where('dkim_verified', true)
                ->exists(),
            // A system server assigned to this tenant counts: the account can
            // send, which is what the step is asking.
            'configure_server' => DeliveryServer::withoutTenancy()
                ->where(fn ($q) => $q->where('customer_id', $customerId)->orWhereNull('customer_id'))
                ->where('status', DeliveryServerStatus::Active->value)
                ->exists(),
            'create_campaign' => Campaign::withoutTenancy()->where('customer_id', $customerId)->exists(),
            'send_campaign' => Campaign::withoutTenancy()
                ->where('customer_id', $customerId)
                ->where('status', CampaignStatus::Sent->value)
                ->exists(),
        ];

        $steps = array_map(
            static fn (string $key): array => ['key' => $key, 'done' => (bool) $done[$key]],
            self::STEPS,
        );

        $completed = count(array_filter($done));
        $total = count(self::STEPS);

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($completed * 100 / $total) : 0,
            'finished' => $completed === $total,
        ];
    }
}
