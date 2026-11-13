<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListPageType;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customized content for one public list page (spec §2.2.12 / §1.5). A row
 * exists only after the customer edits a page; ListPageResolver merges these
 * overrides with the translatable defaults. Public rendering is WP-09.
 */
class ListPage extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ListPageType::class,
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }
}
