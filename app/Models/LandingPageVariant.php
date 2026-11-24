<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\LandingPageVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One arm of a landing-page A/B test (spec §2.6 F-2.6-004).
 *
 * A variant that has no content of its own falls back to the parent page's
 * content, which is what makes "add a variant" a one-click operation: the
 * control arm is the page as it already is.
 */
class LandingPageVariant extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<LandingPageVariantFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'is_control' => 'boolean',
            'is_winner' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $variant): void {
            $variant->uid ??= (string) Str::uuid();
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class, 'landing_page_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(LandingPageVisit::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(LandingPageConversion::class);
    }

    /** The document this arm serves: its own content, else the page's. */
    public function effectiveHtml(LandingPage $page): ?string
    {
        $own = (string) $this->content_html;

        return trim($own) === '' ? $page->content_html : $own;
    }
}
