<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant native-integration credentials (spec §2.12 F-2.12-012, WP-45).
 *
 * The GA4 reasoning (WP-40, CustomerAnalyticsSetting) generalised: a Salesforce
 * refresh token or a Slack webhook URL belongs to the ACCOUNT, not to the
 * install, so it may never live in an operator config file. `credentials` uses
 * the `encrypted` cast.
 *
 * @property int $customer_id
 * @property array<string, mixed>|null $credentials
 */
class CustomerIntegrationCredential extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected $attributes = [
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    /** The row for a tenant + integration, created on demand. */
    public static function forCustomer(int $customerId, string $integration): self
    {
        /** @var self $row */
        $row = self::withoutTenancy()->firstOrCreate([
            'customer_id' => $customerId,
            'integration' => $integration,
        ]);

        return $row;
    }

    public function credential(string $key, ?string $default = null): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
