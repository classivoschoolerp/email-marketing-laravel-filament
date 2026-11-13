<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved builder revision (spec §2.5.4 F-2.5.4-008). Access is always via the
 * parent template (already tenant-checked), so the row itself carries no
 * customer_id. Pruned to the most recent config('emp-templates.revisions_keep').
 */
class TemplateRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
