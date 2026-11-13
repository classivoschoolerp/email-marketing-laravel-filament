<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\I18n\Locales;
use Database\Factories\LanguageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Installed language pack row (spec §3.22, WP-20). Strings live in
 * lang/{code}/ (+ translation_overrides); this table carries the metadata:
 * display name, RTL flag, enabled toggle and the mirrored default flag
 * (source of truth = CommonSettings::default_language, see
 * {@see Locales}).
 */
class Language extends Model
{
    /** @use HasFactory<LanguageFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rtl' => 'boolean',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
