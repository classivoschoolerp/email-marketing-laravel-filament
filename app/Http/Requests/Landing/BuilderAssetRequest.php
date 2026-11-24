<?php

declare(strict_types=1);

namespace App\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Landing builder image upload (spec §2.6 F-2.6-002). Stored on the public
 * disk under the PAGE's own asset directory (spec §0.2 asset isolation).
 * Extension and size limits are shared with the template builder rather than
 * duplicated — one place to tighten them.
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
