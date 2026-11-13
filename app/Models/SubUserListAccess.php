<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-2.15.4-003 "restrict to specific lists / campaigns" (WP-39).
 *
 * Allow-list semantics: NO rows of a resource type = unrestricted for that
 * type; one row or more = the member sees exactly those records and nothing
 * else. Enforced by a global scope registered in routes/modules/team.php,
 * so the restriction holds for every query — UI, jobs and API alike — not
 * just the screens somebody remembered to filter.
 */
class SubUserListAccess extends Model
{
    public const TYPE_LIST = 'list';

    public const TYPE_CAMPAIGN = 'campaign';

    protected $table = 'sub_user_list_access';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
        ];
    }

    public function subUser(): BelongsTo
    {
        return $this->belongsTo(SubUser::class);
    }
}
