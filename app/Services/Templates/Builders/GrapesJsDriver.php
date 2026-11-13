<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;

/**
 * GrapesJS — the P1 drag-and-drop builder (docs/DECISIONS.md D-11, spec §2.5.3
 * F-2.5.3-002). Self-hosted, no external account needed; the editor lives on
 * the Filament builder page backed by resources/js/template-builder.js.
 */
class GrapesJsDriver implements BuilderDriver
{
    public function key(): TemplateBuilder
    {
        return TemplateBuilder::GrapesJs;
    }

    public function label(): string
    {
        return __('templates.builders.grapesjs');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function editUrl(EmailTemplate $template): string
    {
        return EmailTemplateResource::getUrl('builder', ['record' => $template], panel: 'customer');
    }
}
