<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\TemplateSetting;

/**
 * Operator key-value settings for the templates module (WP-10). Deliberately
 * not part of app/Settings (WP-04 owns that); a plain table keeps the module
 * self-contained.
 */
class TemplateSettingsStore
{
    public function get(string $key, ?string $default = null): ?string
    {
        return TemplateSetting::query()->where('key', $key)->value('value') ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        TemplateSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
