<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BounceAction;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Concerns\IsMailboxServer;
use Database\Factories\BounceServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bounce server (spec §2.9.4, WP-15): a POP3/IMAP mailbox polled by
 * bounces:process. customer_id NULL = global row processed for every tenant
 * (§3.13.2). Password encrypted at rest.
 */
class BounceServer extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<BounceServerFactory> */
    use HasFactory;

    use IsMailboxServer;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    /** In-memory defaults mirroring the migration (fresh models act sanely pre-refresh). */
    protected $attributes = [
        'hard_bounce_action' => 'mark-bounced',
        'soft_bounce_action' => 'mark-bounced',
        'soft_bounce_threshold' => 5,
        'delete_after_processing' => true,
        'max_per_batch' => 200,
        'search_charset' => 'UTF-8',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return $this->mailboxCasts() + [
            'hard_bounce_action' => BounceAction::class,
            'soft_bounce_action' => BounceAction::class,
            'soft_bounce_threshold' => 'integer',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CampaignBounceLog::class);
    }
}
