<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateBuilder;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Scopes\CustomerScope;
use App\Services\Templates\TemplateThumbnails;
use App\Support\Templates\TemplateTags;
use App\Support\Tenancy\CurrentCustomer;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Email template (spec §2.5, table email_templates — named to avoid clashing
 * with Blade "template" naming).
 *
 * Tenancy: customer rows carry customer_id and are isolated by the
 * CustomerScope global scope (BelongsToCustomer). Admin/system gallery rows
 * have customer_id NULL — the global scope therefore hides them from customer
 * queries by default. Customers see gallery rows read-only ONLY through the
 * explicit {@see scopeBrowsable()} scope, which the templates index and copy
 * action use; write access to gallery rows is denied by EmailTemplatePolicy.
 */
class EmailTemplate extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'builder' => TemplateBuilder::class,
            // F-2.5.1-002 (D-34 resolved, WP-27B): free-text tags, normalised
            // by App\Support\Templates\TemplateTags on write.
            'tags' => 'array',
            'google_fonts' => 'array',
            'thumbnail_uploaded' => 'boolean',
            'is_default' => 'boolean',
            'is_gallery' => 'boolean',
            'is_featured' => 'boolean',
            'gallery_customer_groups' => 'array',
            'missing_unsubscribe_tag' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->uid ??= (string) Str::uuid();
        });

        // Every write path (Filament form, importer, copier, API) goes through
        // the same normalisation — the JSON filter can then be an exact match.
        static::saving(function (self $template): void {
            if (! $template->isDirty('tags')) {
                return;
            }

            $tags = TemplateTags::normalize($template->tags);

            $template->tags = $tags === [] ? null : $tags;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TemplateCategory::class, 'category_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TemplateRevision::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Everything the current tenant may browse: their own templates plus
     * published system gallery templates whose group restriction matches
     * (spec §2.5.1 "mine vs system gallery", §3.14 group-restricted publish).
     *
     * This is the documented, deliberate escape hatch around CustomerScope for
     * NULL-customer gallery rows — read-only visibility only; policies still
     * deny customers any write on gallery rows. Without a tenant bound (admin
     * context) it simply removes the tenant scope.
     */
    public function scopeBrowsable(Builder $query): Builder
    {
        $customer = app(CurrentCustomer::class)->get();

        $query->withoutGlobalScope(CustomerScope::class);

        if ($customer === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($customer): void {
            $q->where($this->qualifyColumn('customer_id'), $customer->id)
                ->orWhere(fn (Builder $gallery) => $gallery
                    ->whereNull($this->qualifyColumn('customer_id'))
                    ->where('is_gallery', true)
                    ->where(function (Builder $groups) use ($customer): void {
                        $groups->whereNull('gallery_customer_groups');

                        if ($customer->customer_group_id !== null) {
                            $groups->orWhereJsonContains(
                                'gallery_customer_groups',
                                (int) $customer->customer_group_id
                            );
                        }
                    }));
        });
    }

    /** System gallery row (owned by the operator, customer_id NULL)? */
    public function isSystem(): bool
    {
        return $this->customer_id === null;
    }

    /** May this gallery row be shown to the given customer (§3.14 F-3.14-002)? */
    public function isVisibleToCustomer(Customer $customer): bool
    {
        if (! $this->isSystem()) {
            return (int) $this->customer_id === (int) $customer->id;
        }

        if (! $this->is_gallery) {
            return false;
        }

        $groups = $this->gallery_customer_groups;

        return $groups === null
            || in_array((int) $customer->customer_group_id, array_map('intval', $groups), true);
    }

    /**
     * Directory (public disk) holding this template's extracted/uploaded
     * assets. Spec §0.2 asset isolation: customers/{uid}/templates/{uid};
     * system rows live under gallery/templates/{uid}.
     */
    public function assetDirectory(): string
    {
        if ($this->isSystem()) {
            return 'gallery/templates/'.$this->uid;
        }

        $customerUid = $this->customer()->withoutGlobalScopes()->first()?->uid ?? 'unknown';

        return 'customers/'.$customerUid.'/templates/'.$this->uid;
    }

    /**
     * Thumbnail URL (F-2.5.1-001 / F-2.5.5-003). thumbnail_path may hold a
     * storage path or an absolute URL (first-image extraction can point at a
     * remote image).
     *
     * When no image was uploaded or extracted, WP-27B serves the deterministic
     * SVG preview derived from the template's own markup (dominant colours,
     * first heading, one bar per text block) as a data: URI — computed on read
     * so nothing large is stored. That preview is NOT a screenshot; a real
     * screenshot needs headless Chrome, see TemplateThumbnails and
     * docs/e2e-checklist.md. Genuinely empty templates still get the bundled
     * placeholder.
     */
    public function thumbnailUrl(): string
    {
        $path = $this->thumbnail_path;

        if ($path === null || $path === '') {
            return app(TemplateThumbnails::class)->previewDataUri($this)
                ?? asset('images/template-placeholder.svg');
        }

        if (Str::startsWith($path, ['http://', 'https://', 'data:'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
