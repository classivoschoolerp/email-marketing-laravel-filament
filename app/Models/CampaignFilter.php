<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One §2.3.7 recipient filter row. See the migration for the include/exclude
 * combination semantics; the query itself lives in CampaignRecipients.
 */
class CampaignFilter extends Model
{
    public const TYPE_OPENED = 'opened';

    public const TYPE_NOT_OPENED = 'not_opened';

    public const TYPE_CLICKED = 'clicked';

    public const TYPE_NOT_CLICKED = 'not_clicked';

    /** @var list<string> */
    public const TYPES = [self::TYPE_OPENED, self::TYPE_NOT_OPENED, self::TYPE_CLICKED, self::TYPE_NOT_CLICKED];

    public const MODE_INCLUDE = 'include';

    public const MODE_EXCLUDE = 'exclude';

    /** @var list<string> */
    public const MODES = [self::MODE_INCLUDE, self::MODE_EXCLUDE];

    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function targetCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'target_campaign_id');
    }

    /** Click filters measure clicks; open filters measure opens. */
    public function measuresClicks(): bool
    {
        return $this->type === self::TYPE_CLICKED || $this->type === self::TYPE_NOT_CLICKED;
    }

    /** "not_*" types assert the ABSENCE of the event. */
    public function isNegated(): bool
    {
        return $this->type === self::TYPE_NOT_OPENED || $this->type === self::TYPE_NOT_CLICKED;
    }
}
