<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A public "erase my data" request (spec §3.25.16 F-3.25.16-003, §4.5
 * F-4.5-001). Install-wide (never tenant-scoped): one email address may sit
 * on lists owned by several customers, so the admin queue owns the review.
 */
class ErasureRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'erased_count' => 'integer',
            'confirmed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->uid ??= (string) Str::uuid();
            $request->email = mb_strtolower(trim((string) $request->email));
            $request->status ??= self::STATUS_PENDING;
        });
    }

    /** Only a confirmed request may be processed by an administrator. */
    public function isProcessable(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => __('privacy.erasure.statuses.pending'),
            self::STATUS_CONFIRMED => __('privacy.erasure.statuses.confirmed'),
            self::STATUS_COMPLETED => __('privacy.erasure.statuses.completed'),
            self::STATUS_REJECTED => __('privacy.erasure.statuses.rejected'),
        ];
    }
}
