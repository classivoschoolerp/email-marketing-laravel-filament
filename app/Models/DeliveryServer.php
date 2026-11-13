<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryServerStatus;
use App\Enums\QuotaExceededAction;
use App\Enums\ServerProtocol;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Scopes\CustomerScope;
use App\Support\Tenancy\CurrentCustomer;
use Database\Factories\DeliveryServerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Delivery server (spec §2.9.2, WP-14).
 *
 * Tenancy: customer rows carry customer_id (CustomerScope via
 * BelongsToCustomer). Admin/system rows have customer_id NULL and are visible
 * to customers READ-ONLY through {@see scopeBrowsable()} when the row's
 * customer_groups assignment matches (F-2.9.2-037) — the same pattern as the
 * EmailTemplate gallery. Writes on system rows are denied by
 * DeliveryServerPolicy.
 *
 * password, api_key and dkim_private_key are encrypted at rest (F-2.9.2-025,
 * F-2.9.2-036; twofa_secret convention).
 */
class DeliveryServer extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<DeliveryServerFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'api_key', 'dkim_private_key', 'validation_token'];

    protected function casts(): array
    {
        return [
            'status' => DeliveryServerStatus::class,
            'protocol' => ServerProtocol::class,
            'quota_exceeded_action' => QuotaExceededAction::class,
            'password' => 'encrypted',
            'api_key' => 'encrypted',
            'dkim_private_key' => 'encrypted',
            'force_from' => 'boolean',
            'force_reply_to' => 'boolean',
            'keep_alive' => 'boolean',
            'dkim_signing_enabled' => 'boolean',
            'locked' => 'boolean',
            'probability' => 'integer',
            'hourly_quota' => 'integer',
            'daily_quota' => 'integer',
            'monthly_quota' => 'integer',
            'max_connection_messages' => 'integer',
            'connection_timeout' => 'integer',
            'custom_headers' => 'array',
            'customer_groups' => 'array',
            'config' => 'array',
            'validated_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $server): void {
            $server->uid ??= (string) Str::uuid();
        });
    }

    public function usage(): HasMany
    {
        return $this->hasMany(DeliveryServerUsage::class);
    }

    /** WP-33 §2.9.3 — the assigned warmup plan (warmup_plan_id, no FK). */
    public function warmupPlan(): BelongsTo
    {
        return $this->belongsTo(WarmupPlan::class, 'warmup_plan_id')->withoutGlobalScopes();
    }

    /** WP-33 §2.9.3 F-2.9.3-011 — this server's own warmup state. */
    public function warmupProgress(): HasOne
    {
        return $this->hasOne(WarmupProgress::class);
    }

    /** WP-33 §3.13.1 F-3.13.1-008 — pools this server belongs to. */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            DeliveryServerGroup::class,
            'delivery_server_group_members',
            'delivery_server_id',
            'delivery_server_group_id',
        )->withoutGlobalScopes()->withTimestamps();
    }

    /** WP-33 §3.13.1 F-3.13.1-009 — dedicated IPs bound to this server. */
    public function ips(): HasMany
    {
        return $this->hasMany(DeliveryServerIp::class);
    }

    /**
     * WP-42 §2.9.2 F-2.9.2-038 — "assign to specific lists/campaigns only".
     * No rows = no restriction; see App\Services\Sending\DeliveryServerAssignments.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryServerAssignment::class);
    }

    /**
     * Everything the current tenant may browse: their own servers plus system
     * servers assigned to their customer group (null assignment = every
     * group). Read-only visibility — DeliveryServerPolicy denies writes on
     * system rows. Without a bound tenant it just removes the tenant scope.
     */
    public function scopeBrowsable(Builder $query): Builder
    {
        $customer = app(CurrentCustomer::class)->get();

        $query->withoutGlobalScope(CustomerScope::class);

        if ($customer === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($customer): void {
            $q->where($this->qualifyColumn('customer_id'), $customer->id)
                ->orWhere(fn (Builder $system) => $system
                    ->whereNull($this->qualifyColumn('customer_id'))
                    ->where(function (Builder $groups) use ($customer): void {
                        $groups->whereNull('customer_groups');

                        if ($customer->customer_group_id !== null) {
                            $groups->orWhereJsonContains(
                                'customer_groups',
                                (int) $customer->customer_group_id
                            );
                        }
                    }));
        });
    }

    /** System/admin row (customer_id NULL)? */
    public function isSystem(): bool
    {
        return $this->customer_id === null;
    }

    /** May the given customer see this server (own row, or assigned system row)? */
    public function isVisibleToCustomer(Customer $customer): bool
    {
        if (! $this->isSystem()) {
            return (int) $this->customer_id === (int) $customer->id;
        }

        $groups = $this->customer_groups;

        return $groups === null
            || in_array((int) $customer->customer_group_id, array_map('intval', $groups), true);
    }

    /** A customer may edit only their own, unlocked rows (F-2.9.2-040). */
    public function isEditableByCustomer(Customer $customer): bool
    {
        return ! $this->isSystem()
            && (int) $this->customer_id === (int) $customer->id
            && ! $this->locked;
    }

    /** Cache key for the hourly fixed-window counter (HourlyWindowLimiter). */
    public function hourlyQuotaKey(): string
    {
        return 'delivery-server:'.$this->id;
    }

    /** Config value from the per-driver json column. */
    public function configValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function isActive(): bool
    {
        return $this->status === DeliveryServerStatus::Active;
    }

    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }
}
