<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LandingPageStatus;
use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\LandingPageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A landing page (spec §2.6, rendered by §1.8).
 *
 * The `slug` is the public key: every public route resolves by slug and
 * refuses anything that is not {@see isLive()}, so a page that is a draft,
 * unpublished, or scheduled for the future is a 404 no matter who asks.
 *
 * Content is stored exactly like an §2.5.4 email template — `content_html`
 * (the rendered snapshot the public page serves) plus `content_json` (the
 * GrapesJS project state the builder reloads) — which is what lets the
 * landing builder be the same editor, the same revision history and the same
 * device preview rather than a third implementation.
 */
class LandingPage extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<LandingPageFactory> */
    use HasFactory;

    use SoftDeletes;

    /** After-submit handling (F-2.6-011). */
    public const AFTER_MESSAGE = 'message';

    public const AFTER_THANK_YOU = 'thank_you';

    public const AFTER_REDIRECT = 'redirect';

    /** @var list<string> */
    public const AFTER_MODES = [self::AFTER_MESSAGE, self::AFTER_THANK_YOU, self::AFTER_REDIRECT];

    /** Robots directives offered by the SEO tab (F-2.6-007). */
    public const ROBOTS = [
        'index,follow',
        'noindex,follow',
        'index,nofollow',
        'noindex,nofollow',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LandingPageStatus::class,
            'ab_enabled' => 'boolean',
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $page): void {
            $page->uid ??= (string) Str::uuid();
            $page->slug = self::uniqueSlug((string) ($page->slug ?: $page->name), null);
        });
    }

    /* ================================================================ *
     *  Relations
     * ================================================================ */

    public function variants(): HasMany
    {
        return $this->hasMany(LandingPageVariant::class)->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(LandingPageRevision::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(LandingPageVisit::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(LandingPageConversion::class);
    }

    public function trackingDomain(): BelongsTo
    {
        return $this->belongsTo(TrackingDomain::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * The embedded form resolved WITHOUT the tenancy scope.
     *
     * The public render keys off the slug alone and must not change shape
     * based on who (if anyone) is logged in — eager-loading through the
     * relation would apply CustomerScope and silently drop the form
     * (Form::publicList() makes the same point for the same reason).
     */
    public function publicForm(): ?Form
    {
        return $this->form_id === null ? null : Form::withoutTenancy()->find($this->form_id);
    }

    /** The assigned custom domain, resolved without tenancy (public render). */
    public function publicTrackingDomain(): ?TrackingDomain
    {
        return $this->tracking_domain_id === null
            ? null
            : TrackingDomain::withoutTenancy()->find($this->tracking_domain_id);
    }

    /** Enabled variants, without tenancy — the public serve path has no session. */
    public function publicVariants(): array
    {
        return LandingPageVariant::withoutTenancy()
            ->where('landing_page_id', $this->id)
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /* ================================================================ *
     *  Lifecycle
     * ================================================================ */

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', LandingPageStatus::Published->value);
    }

    /**
     * Is this page servable right now? Published always; Scheduled once its
     * moment has passed (see the enum docblock — no cron is involved).
     */
    public function isLive(): bool
    {
        if ($this->status === LandingPageStatus::Published) {
            return true;
        }

        return $this->status === LandingPageStatus::Scheduled
            && $this->scheduled_at !== null
            && $this->scheduled_at->isPast();
    }

    public function displayTitle(): string
    {
        return (string) (trim((string) $this->seo_title) ?: $this->name);
    }

    /**
     * §1.8 F-1.8-006 — a gated page. The stored value is a hash (see the
     * migration); there is no second boolean that could disagree with it.
     */
    public function isPasswordProtected(): bool
    {
        return trim((string) $this->access_password) !== '';
    }

    /**
     * Where builder uploads live (spec §0.2 asset isolation) — per tenant and
     * per page, so duplicating or deleting a page never disturbs another.
     */
    public function assetDirectory(): string
    {
        $customerUid = $this->customer()->withoutGlobalScopes()->first()?->uid ?? 'unknown';

        return 'customers/'.$customerUid.'/landing/'.$this->uid;
    }

    /* ================================================================ *
     *  Slugs
     * ================================================================ */

    /**
     * A URL-safe, globally unique slug.
     *
     * Global (not per-tenant) because the slug is the whole public path on a
     * host shared by every tenant: two customers cannot both own `/p/pricing`.
     * CJK/emoji names slugify to an empty string, so a stable uid-derived
     * fallback keeps those pages addressable instead of failing to save.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = Str::limit(Str::slug($source), 150, '');

        if ($base === '') {
            $base = 'page-'.Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 1;

        while (self::slugTaken($slug, $ignoreId)) {
            $suffix++;
            $slug = Str::limit($base, 140, '').'-'.$suffix;
        }

        return $slug;
    }

    private static function slugTaken(string $slug, ?int $ignoreId): bool
    {
        $query = self::withoutTenancy()->withTrashed()->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
