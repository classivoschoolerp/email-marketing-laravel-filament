<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operator key-value setting for the templates module (WP-10). Deliberately
 * separate from app/Settings (WP-04). Accessed via
 * App\Services\Templates\TemplateSettingsStore.
 */
class TemplateSetting extends Model
{
    protected $guarded = ['id'];
}
