<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;

/**
 * Raw HTML editor — the always-available fallback (spec §2.5.3 F-2.5.3-006).
 */
class RawHtmlDriver implements BuilderDriver
{
    public function key(): TemplateBuilder
    {
        return TemplateBuilder::Html;
    }

    public function label(): string
    {
        return __('templates.builders.html');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function editUrl(EmailTemplate $template): string
    {
        return EmailTemplateResource::getUrl('edit', ['record' => $template], panel: 'customer');
    }
}
