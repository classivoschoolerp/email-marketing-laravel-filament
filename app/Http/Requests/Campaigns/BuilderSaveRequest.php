<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaigns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Campaign builder autosave payload (spec §2.3.3 F-2.3.3-002).
 * Authorization happens in the controller against the resolved campaign
 * (CampaignPolicy::update + edit-until-sending).
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
        ];
    }
}
