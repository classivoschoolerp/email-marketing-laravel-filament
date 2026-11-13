<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Save an edited image (spec §2.5.4 F-2.5.4-009, WP-42).
 *
 * The editing itself — crop, resize, filters, text overlay — happens entirely
 * in the browser on a <canvas>; `canvas.toBlob()` is posted here as an
 * ordinary multipart upload. The server therefore validates an IMAGE, exactly
 * like BuilderAssetRequest does, and never has to parse a data URL.
 *
 * Raster only: an edited SVG is a contradiction (the canvas rasterises), so
 * the type list here is deliberately narrower than the builder asset list.
 */
class EditedImageRequest extends FormRequest
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
                File::types(['png', 'jpg', 'jpeg', 'webp'])
                    ->max((int) ceil(((int) config('emp-templates.image_editor.max_bytes', 8_388_608)) / 1024)),
            ],
            'name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
