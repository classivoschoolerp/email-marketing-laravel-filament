<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A standalone capture form / popup (spec §2.7).
 *
 * The `uid` is the public access key: every public route (render, embed
 * loader, impression beacon, submit) resolves by uid and refuses anything
 * that is not {@see FormStatus::Active}, so a snippet left on a customer's
 * site stops rendering the moment the form is paused.
 *
 * @property array<string, mixed>|null $display_rules
 * @property array<string, mixed>|null $frequency_rules
 * @property array<string, mixed>|null $styles
 */
class Form extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<FormFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Success handling (F-2.7-008). */
    public const SUCCESS_MESSAGE = 'message';

    public const SUCCESS_REDIRECT = 'redirect';

    public const SUCCESS_DOWNLOAD = 'download';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => FormType::class,
            'status' => FormStatus::class,
            'consent_enabled' => 'boolean',
            'consent_required' => 'boolean',
            'captcha_enabled' => 'boolean',
            'multi_step' => 'boolean',
            'ab_enabled' => 'boolean',
            'display_rules' => 'array',
            'frequency_rules' => 'array',
            'styles' => 'array',
            'steps' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $form): void {
            $form->uid ??= (string) Str::uuid();
        });
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    /**
     * The target list resolved WITHOUT the tenancy scope.
     *
     * The public endpoints identify a form by uid alone and must not depend
     * on who (if anyone) is logged in: eager-loading `list` through the
     * relation applies CustomerScope, so a form belonging to another tenant
     * would silently resolve its list to NULL and 404 a perfectly valid
     * public submission.
     */
    public function publicList(): ?EmailList
    {
        return EmailList::withoutTenancy()->find($this->list_id);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** A/B arms of this form (F-2.7-013). */
    public function variants(): HasMany
    {
        return $this->hasMany(FormVariant::class)->orderBy('id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(FormStatDaily::class);
    }

    /** The Consent-type list field the GDPR tick is written to (F-2.7-009). */
    public function consentField(): BelongsTo
    {
        return $this->belongsTo(ListField::class, 'consent_list_field_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', FormStatus::Active->value);
    }

    public function isPublic(): bool
    {
        return $this->status instanceof FormStatus && $this->status->isPublic();
    }

    public function displayName(): string
    {
        return (string) ($this->heading ?: $this->name);
    }

    /**
     * Ordered fields with their choice options eager-loaded.
     *
     * Without the tenancy scope, for the same reason as
     * {@see publicList()}: the public render and submit paths key off the
     * form uid alone and must not change shape based on who is logged in.
     *
     * @return list<FormField>
     */
    public function orderedFields(): array
    {
        return FormField::withoutTenancy()
            ->where('form_id', $this->id)
            ->with('options')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The enabled A/B arms, resolved WITHOUT the tenancy scope for exactly the
     * reason {@see publicList()} gives: variant assignment happens on a public
     * request that has no session, and reading the relation would apply
     * CustomerScope and hand every visitor the control arm (D-95).
     *
     * @return list<FormVariant>
     */
    public function publicVariants(): array
    {
        return FormVariant::withoutTenancy()
            ->where('form_id', $this->id)
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** The GDPR consent target list field, resolved without tenancy. */
    public function publicConsentField(): ?ListField
    {
        return $this->consent_list_field_id === null
            ? null
            : ListField::withoutTenancy()->find($this->consent_list_field_id);
    }
}
