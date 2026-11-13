<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Services\Templates\Stock\StockImageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Insert this stock photo" (spec §2.5.4 F-2.5.4-010, WP-42).
 *
 * The URL is only shape-checked here; the real fence — https + a host the
 * chosen library actually serves images from — is in StockImageImporter,
 * because it depends on the provider and must hold for every caller.
 */
class StockImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(app(StockImageRegistry::class)->configuredKeys())],
            'url' => ['required', 'string', 'url', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['provider' => __('builder.stock.fields.provider')];
    }
}
