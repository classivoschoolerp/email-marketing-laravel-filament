<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Active session listing + revocation off the database sessions table
 * (spec §2.15.2 F-2.15.2-002). Ownership is resolved from the serialized
 * session payload's customer-guard login key — the table's user_id column is
 * written for the default guard only, which is not the customer guard.
 */
class SessionManager
{
    public function usesDatabaseDriver(): bool
    {
        return config('session.driver') === 'database' && Schema::hasTable('sessions');
    }

    /**
     * @return list<array{id: string, ip: ?string, user_agent: ?string, last_activity: CarbonImmutable, current: bool}>
     */
    public function forCustomer(Customer $customer, ?string $currentSessionId = null): array
    {
        if (! Schema::hasTable('sessions')) {
            return [];
        }

        $sessions = [];

        foreach (DB::table('sessions')->orderByDesc('last_activity')->cursor() as $row) {
            if (! $this->belongsTo($customer, (string) $row->payload)) {
                continue;
            }

            $sessions[] = [
                'id' => (string) $row->id,
                'ip' => $row->ip_address !== null ? (string) $row->ip_address : null,
                'user_agent' => $row->user_agent !== null ? (string) $row->user_agent : null,
                'last_activity' => CarbonImmutable::createFromTimestampUTC((int) $row->last_activity),
                'current' => $currentSessionId !== null && hash_equals($currentSessionId, (string) $row->id),
            ];
        }

        return $sessions;
    }

    /**
     * Deletes the given session if — and only if — it belongs to the customer.
     */
    public function revoke(Customer $customer, string $sessionId): bool
    {
        if (! Schema::hasTable('sessions')) {
            return false;
        }

        $row = DB::table('sessions')->where('id', $sessionId)->first();

        if ($row === null || ! $this->belongsTo($customer, (string) $row->payload)) {
            return false;
        }

        return DB::table('sessions')->where('id', $sessionId)->delete() > 0;
    }

    /**
     * The session key Laravel's SessionGuard uses to store the authenticated
     * customer id, e.g. "login_customer_<sha1>".
     */
    public function guardSessionKey(): string
    {
        $guard = Auth::guard('customer');

        return method_exists($guard, 'getName')
            ? $guard->getName()
            : 'login_customer_'.sha1(SessionGuard::class);
    }

    private function belongsTo(Customer $customer, string $payload): bool
    {
        $data = $this->decodePayload($payload);

        return array_key_exists($this->guardSessionKey(), $data)
            && (int) $data[$this->guardSessionKey()] === (int) $customer->getKey();
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $payload): array
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return [];
        }

        $data = @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }
}
