<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Campaign attachment row (spec §2.3.4 F-2.3.4-015); the file itself lives
 * on the public disk under the campaign's asset directory.
 */
class CampaignAttachment extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $attachment): void {
            if ($attachment->path !== null && Storage::disk('public')->exists($attachment->path)) {
                Storage::disk('public')->delete($attachment->path);
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
