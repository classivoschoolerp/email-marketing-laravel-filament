<?php

declare(strict_types=1);

namespace App\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Landing builder autosave payload (spec §2.6 F-2.6-002/005). Authorization
 * happens in the controller against the resolved page
 * (LandingPagePolicy::update) — the same split the §2.5.4 template builder
 * uses, so a cross-tenant uid is a deterministic 403 rather than a 404 that
 * depends on the session's tenant.
 */
class BuilderSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content_html' => ['nullable', 'string', 'max:2097152'],
            'content_json' => ['nullable', 'string', 'json', 'max:4194304'],
            // Which document is being saved: the page, or one A/B arm.
            'variant_uid' => ['nullable', 'string', 'max:64'],
        ];
    }
}
