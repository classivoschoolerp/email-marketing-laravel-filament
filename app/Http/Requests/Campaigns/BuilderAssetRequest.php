<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaigns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Campaign builder image upload (spec §2.3.3 F-2.3.3-016). Stored on the
 * public disk under the campaign's asset directory (spec §0.2 isolation).
 */
class BuilderAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(config('emp-templates.assets.extensions', []))
                    ->max((int) config('emp-templates.assets.max_kb', 5120)),
            ],
        ];
    }
}
