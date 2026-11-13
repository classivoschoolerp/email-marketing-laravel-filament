<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Services\Templates\Stock\StockImageRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stock library search (spec §2.5.4 F-2.5.4-010, WP-42).
 *
 * `provider` is validated against the CONFIGURED libraries, so an install
 * with no API key rejects every provider value — which is exactly how "no
 * key configured = the picker is unavailable" is enforced server-side, not
 * merely hidden in the UI.
 */
class StockSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'provider' => __('builder.stock.fields.provider'),
            'q' => __('builder.stock.fields.query'),
        ];
    }
}
