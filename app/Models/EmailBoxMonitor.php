<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Models\Concerns\IsMailboxServer;
use Database\Factories\EmailBoxMonitorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Email box monitor (spec §2.9.6, WP-33): a POP3/IMAP mailbox whose messages
 * are matched against ordered conditions, each carrying an action. Reuses the
 * WP-15 mailbox machinery through IsMailboxServer. customer_id NULL = global
 * monitor. Password encrypted at rest.
 */
class EmailBoxMonitor extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<EmailBoxMonitorFactory> */
    use HasFactory;

    use IsMailboxServer;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    /** In-memory defaults mirroring the migration. */
    protected $attributes = [
        'port' => 995,
        'protocol' => 'pop3',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'delete_after_processing' => true,
        'max_per_batch' => 200,
        'search_charset' => 'UTF-8',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return $this->mailboxCasts();
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(EmailBoxMonitorCondition::class)
            ->orderBy('priority')
            ->orderBy('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EmailBoxMonitorLog::class);
    }
}
