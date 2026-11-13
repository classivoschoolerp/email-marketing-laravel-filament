<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Builder autosave payload (spec §2.5.4 F-2.5.4-008). Authorization happens in
 * the controller against the resolved template (EmailTemplatePolicy::update).
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
            'google_fonts' => ['nullable', 'array', 'max:10'],
            'google_fonts.*' => ['string', 'url', 'max:500', function (string $attribute, mixed $value, Closure $fail): void {
                $host = parse_url((string) $value, PHP_URL_HOST);
                $allowed = config('emp-templates.google_fonts_hosts', []);

                if (! is_string($host) || ! in_array(Str::lower($host), $allowed, true)) {
                    $fail(__('templates.errors.font_host', ['hosts' => implode(', ', $allowed)]));
                }
            }],
        ];
    }
}
