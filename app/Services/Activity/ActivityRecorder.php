<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\SubUser;
use App\Support\Team\SubUserContext;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * §2.15.7 F-2.15.7-001 — the one place an account action becomes a row
 * (WP-39).
 *
 * Scope, stated so nothing is double-counted: this log records what the
 * ACCOUNT did to its own data (created a list, sent a campaign, changed a
 * setting, deleted a subscriber). It deliberately does NOT mirror
 *   - `login_logs`      — authentication events, already recorded there and
 *                         surfaced on the Security page;
 *   - `consent_events`  — subscriber consent changes (§3.25.16);
 *   - `impersonation_logs` — admin access to the tenant (WP-03).
 * Those tables stay the authority for their domains.
 *
 * Recording never throws: an audit write must not be able to fail the
 * business action it is describing.
 */
class ActivityRecorder
{
    public function __construct(
        private readonly CurrentCustomer $currentCustomer,
        private readonly SubUserContext $subUsers,
    ) {}

    /**
     * WP-40 §3.27 F-3.27-011 — a caller that knows what a field changed FROM
     * and TO passes both maps; the §3.27 audit viewer renders them as a
     * before/after diff. Callers that do not (a create, a delete, a
     * hand-written line) leave them null and the viewer says the entry has
     * no field-level change instead of inventing an empty one.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $properties = [],
        ?Customer $customer = null,
        ?string $subjectLabel = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): ?ActivityLog {
        try {
            $customer = $customer ?? $this->resolveCustomer($subject);

            if ($customer === null) {
                return null;
            }

            $actor = $this->resolveActor($customer);

            return ActivityLog::withoutTenancy()->create([
                'customer_id' => $customer->id,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'actor_name' => $this->actorName($actor),
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subjectLabel ?? $this->subjectLabel($subject),
                'description' => $description,
                'properties' => $properties === [] ? null : $properties,
                'old_values' => $oldValues === null || $oldValues === [] ? null : $oldValues,
                'new_values' => $newValues === null || $newValues === [] ? null : $newValues,
                'ip' => $this->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The customer the row belongs to: an explicit argument wins, then the
     * subject's own tenant column, then the request tenant.
     */
    private function resolveCustomer(?Model $subject): ?Customer
    {
        $current = $this->currentCustomer->get();

        if ($current !== null) {
            return $current;
        }

        $customerId = $subject?->getAttribute('customer_id');

        if ($customerId === null) {
            return null;
        }

        return Customer::query()->withoutGlobalScopes()->find((int) $customerId);
    }

    /**
     * Who acted: the team member when one is driving the session, otherwise
     * the account owner. Never an admin — admin actions on a tenant are
     * impersonation_logs' job.
     */
    private function resolveActor(Customer $customer): Customer|SubUser|null
    {
        $subUser = $this->subUsers->current();

        if ($subUser !== null && (int) $subUser->customer_id === (int) $customer->id) {
            return $subUser;
        }

        $authenticated = Auth::guard('customer')->user();

        return $authenticated instanceof Customer && (int) $authenticated->id === (int) $customer->id
            ? $authenticated
            : null;
    }

    private function actorName(Customer|SubUser|null $actor): ?string
    {
        if ($actor instanceof SubUser) {
            return $actor->fullName();
        }

        if ($actor instanceof Customer) {
            $name = trim((string) $actor->fullName);

            return $name !== '' ? $name : (string) $actor->email;
        }

        return null;
    }

    private function subjectLabel(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        foreach (['name', 'title', 'subject', 'email'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }

    private function ip(): ?string
    {
        try {
            return request()->ip();
        } catch (Throwable) {
            return null;
        }
    }
}
