<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One completed form submission (spec §2.7). Written AFTER the subscriber
 * has been created/updated through the model, so the row always references a
 * subscriber that already went through the list's cascades.
 *
 * @property array<string, mixed>|null $data
 */
class FormSubmission extends Model
{
    use BelongsToCustomer;

    /** Attribution bucket when nothing identifies the traffic (F-2.7-012). */
    public const SOURCE_DIRECT = 'direct';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'consent_given' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $submission): void {
            $submission->uid ??= (string) Str::uuid();
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
