<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Published canvas snapshot (F-2.4.2-002). */
class AutomationVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'canvas' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class)->withoutGlobalScopes();
    }
}
