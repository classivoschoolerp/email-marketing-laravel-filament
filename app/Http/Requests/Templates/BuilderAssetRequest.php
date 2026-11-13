<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Builder image upload (spec §2.5.4 image block). Stored on the public disk
 * under the template's asset directory (spec §0.2 asset isolation).
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
