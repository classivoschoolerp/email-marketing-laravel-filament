<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SendFrequency;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Enums\VerificationStatus;
use App\Models\Concerns\BelongsToCustomer;
use App\Services\Lists\SubscriberActionService;
use Database\Factories\SubscriberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A list member (spec §2.2.4/§2.2.5). Status transitions run through model
 * events so the list's subscriber-actions (F-2.2.2-015..018) are enforced on
 * every code path — UI, jobs, API — never just the UI layer.
 */
class Subscriber extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<SubscriberFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriberStatus::class,
            'source' => SubscriberSource::class,
            'subscribed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_open_at' => 'datetime',
            'last_click_at' => 'datetime',
            'last_bounce_at' => 'datetime',
            'bounces_count' => 'integer',
            'complaints_count' => 'integer',
            // WP-16 (§2.9.12 F-2.9.12-006): verification result tagging.
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
            // WP-42 (§1.5 F-1.5-012): preference-center send frequency.
            'send_frequency' => SendFrequency::class,
            'frequency_paused_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $subscriber): void {
            $subscriber->uid ??= (string) Str::uuid();
            $subscriber->email = mb_strtolower(trim((string) $subscriber->email));
            $subscriber->subscribed_at ??= now();

            if ($subscriber->status === SubscriberStatus::Confirmed) {
                $subscriber->confirmed_at ??= now();
            }
        });

        static::updating(function (self $subscriber): void {
            if (! $subscriber->isDirty('status')) {
                return;
            }

            // Autofill lifecycle timestamps on transition (F-2.2.5-002).
            match ($subscriber->status) {
                SubscriberStatus::Confirmed => $subscriber->confirmed_at ??= now(),
                SubscriberStatus::Unsubscribed => $subscriber->unsubscribed_at ??= now(),
                default => null,
            };
        });

        static::created(function (self $subscriber): void {
            if ($subscriber->status->isActive()) {
                app(SubscriberActionService::class)->handleSubscribe($subscriber);
            }
        });

        static::updated(function (self $subscriber): void {
            if (! $subscriber->wasChanged('status')) {
                return;
            }

            $original = $subscriber->getOriginal('status');
            $old = $original instanceof SubscriberStatus
                ? $original
                : ($original === null ? null : SubscriberStatus::from((string) $original));

            app(SubscriberActionService::class)->handleStatusChange($subscriber, $old, $subscriber->status);
        });
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(SubscriberFieldValue::class);
    }

    /**
     * Subscriber tags (F-2.2.5-004). Storage arrived with WP-21 for the
     * automation tag triggers; this relation is what the profile editor and
     * the index filter read. Attach/detach still goes through
     * App\Services\Subscribers\SubscriberTagService so the automation hooks
     * keep firing.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(SubscriberTag::class);
    }
}
