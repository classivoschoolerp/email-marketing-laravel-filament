<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Services\Content\InboxPreview\InboxPreviewScreen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One inbox rendering preview submission (spec §2.3.5 F-2.3.5-006, WP-46).
 */
class InboxPreviewRun extends Model
{
    use BelongsToCustomer;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'clients' => 'array',
            'results' => 'array',
            'polls' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uid ??= (string) Str::uuid();
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /** @return list<InboxPreviewScreen> */
    public function screens(): array
    {
        return array_map(
            static fn (array $row): InboxPreviewScreen => InboxPreviewScreen::fromArray($row),
            (array) ($this->results ?? []),
        );
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
