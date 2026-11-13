<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OptInType;
use App\Enums\SubscriberStatus;
use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\EmailListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A subscriber list (spec §2.2). Named EmailList because PHP reserves `List`
 * as a keyword; the table stays `lists` per the spec's data model.
 *
 * @property array<int, string>|null $list_tags
 * @property array<int, int>|null $on_subscribe_subscribe_ids
 * @property array<int, int>|null $on_subscribe_unsubscribe_ids
 * @property array<int, int>|null $on_unsubscribe_unsubscribe_ids
 * @property array<int, int>|null $on_unsubscribe_subscribe_ids
 */
class EmailList extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<EmailListFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING_DELETE = 'pending_delete';

    protected $table = 'lists';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opt_in' => OptInType::class,
            'opt_out' => OptInType::class,
            'require_approval' => 'boolean',
            'visible' => 'boolean',
            'list_tags' => 'array',
            'subscribe_notify' => 'boolean',
            'unsubscribe_notify' => 'boolean',
            'on_subscribe_subscribe_ids' => 'array',
            'on_subscribe_unsubscribe_ids' => 'array',
            'on_unsubscribe_unsubscribe_ids' => 'array',
            'on_unsubscribe_subscribe_ids' => 'array',
            'autodelete_unconfirmed_days' => 'integer',
            'autoremove_bounces_count' => 'integer',
            'optin_confirm_expiry_hours' => 'integer',
            'gdpr_consent_logging' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $list): void {
            $list->uid ??= (string) Str::uuid();
        });
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ListField::class, 'list_id')->orderBy('sort_order');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ListSegment::class, 'list_id');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class, 'list_id');
    }

    /** WP-08 §2.2.11: the embed form config singleton for this list. */
    public function form(): HasOne
    {
        return $this->hasOne(ListForm::class, 'list_id');
    }

    /** WP-08 §2.2.12: customized public page content rows. */
    public function pages(): HasMany
    {
        return $this->hasMany(ListPage::class, 'list_id');
    }

    /** WP-08 §2.2.7: import runs into this list (history log). */
    public function imports(): HasMany
    {
        return $this->hasMany(ImportJob::class, 'list_id');
    }

    /** WP-08 §2.2.8: export runs for this list (history log). */
    public function exports(): HasMany
    {
        return $this->hasMany(ExportJob::class, 'list_id');
    }

    /**
     * Per-status subscriber counts for the overview cards (F-2.2.3-001).
     *
     * @return array<string, int> status value => count
     */
    public function statusCounts(): array
    {
        $counts = $this->subscribers()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $result = [];

        foreach (SubscriberStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /** Hard/soft split of bounced subscribers (F-2.2.3-001). */
    public function bounceTypeCounts(): array
    {
        $counts = $this->subscribers()
            ->where('status', SubscriberStatus::Bounced->value)
            ->selectRaw('bounce_type, count(*) as aggregate')
            ->groupBy('bounce_type')
            ->pluck('aggregate', 'bounce_type')
            ->all();

        return [
            'hard' => (int) ($counts['hard'] ?? 0),
            'soft' => (int) ($counts['soft'] ?? 0),
        ];
    }
}
