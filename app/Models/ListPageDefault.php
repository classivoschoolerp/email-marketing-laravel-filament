<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListPageType;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-install admin default content for one list page type (spec §3.16).
 * The middle layer of ListPageResolver's 3-layer merge: per-list override
 * (list_pages) > this row > translatable default (lang/…/lists.php).
 * Global — deliberately NOT tenant-scoped.
 */
class ListPageDefault extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ListPageType::class,
        ];
    }
}
