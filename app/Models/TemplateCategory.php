<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Models\Scopes\CustomerScope;
use App\Support\Tenancy\CurrentCustomer;
use Database\Factories\TemplateCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Template category (spec §2.5.2). Customer-owned rows are tenant-scoped;
 * customer_id NULL rows are system categories used by the admin gallery
 * (§3.14) and shown read-only to customers via {@see scopeBrowsable()} —
 * the same documented escape-hatch pattern as EmailTemplate.
 */
class TemplateCategory extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<TemplateCategoryFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            $category->uid ??= (string) Str::uuid();
        });
    }

    public function templates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'category_id');
    }

    /** Own categories plus system categories (read-only). Documented tenancy escape hatch. */
    public function scopeBrowsable(Builder $query): Builder
    {
        $customer = app(CurrentCustomer::class)->get();

        $query->withoutGlobalScope(CustomerScope::class);

        if ($customer === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($customer): void {
            $q->where($this->qualifyColumn('customer_id'), $customer->id)
                ->orWhereNull($this->qualifyColumn('customer_id'));
        });
    }

    public function isSystem(): bool
    {
        return $this->customer_id === null;
    }
}
