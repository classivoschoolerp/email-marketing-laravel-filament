<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\I18n\OverrideTranslationLoader;
use Illuminate\Database\Eloquent\Model;

/**
 * One overridden translation string (spec §6.5 F-6.5-003, WP-20): merged
 * over the lang/ file value by
 * {@see OverrideTranslationLoader}.
 */
class TranslationOverride extends Model
{
    protected $guarded = ['id'];
}
