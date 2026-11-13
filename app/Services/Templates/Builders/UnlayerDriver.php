<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;

/**
 * Unlayer / BeeFree adapter stub (spec §2.5.3 F-2.5.3-004, P2 per D-11).
 * Registered so the registry shape is proven; unavailable until a project id
 * is configured and the P2 work package builds the embed page.
 */
class UnlayerDriver implements BuilderDriver
{
    public function key(): TemplateBuilder
    {
        return TemplateBuilder::Unlayer;
    }

    public function label(): string
    {
        return __('templates.builders.unlayer');
    }

    public function isAvailable(): bool
    {
        return false; // P2 — flips on when the embed page ships (config emp-templates.unlayer)
    }

    public function editUrl(EmailTemplate $template): string
    {
        return EmailTemplateResource::getUrl('edit', ['record' => $template], panel: 'customer');
    }
}
