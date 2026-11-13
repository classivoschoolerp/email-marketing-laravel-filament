<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Models\Concerns\IsMailboxServer;
use Database\Factories\FblServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Feedback-loop server (spec §2.9.5, WP-15): a POP3/IMAP mailbox receiving
 * ARF complaint reports, polled by fbl:process. customer_id NULL = global row
 * (§3.13.3). Password encrypted at rest.
 */
class FblServer extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<FblServerFactory> */
    use HasFactory;

    use IsMailboxServer;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    /** In-memory defaults mirroring the migration (fresh models act sanely pre-refresh). */
    protected $attributes = [
        'delete_after_processing' => true,
        'max_per_batch' => 200,
        'search_charset' => 'UTF-8',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return $this->mailboxCasts();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CampaignComplaintLog::class);
    }
}
