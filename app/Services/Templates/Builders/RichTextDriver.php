<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;

/**
 * Rich-text editor (Filament RichEditor) — simple WYSIWYG option offered next
 * to the raw HTML fallback (spec §2.5.3).
 */
class RichTextDriver implements BuilderDriver
{
    public function key(): TemplateBuilder
    {
        return TemplateBuilder::RichText;
    }

    public function label(): string
    {
        return __('templates.builders.richtext');
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
