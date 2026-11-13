<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;

/**
 * Basic / simple built-in drag-and-drop builder (spec §2.5.3 F-2.5.3-001,
 * WP-27B).
 *
 * Distinct from GrapesJS (D-11 keeps GrapesJS the P1 builder) and from the
 * external vendors: no canvas, no bundled editor library, no vendor account.
 * The editor is a server-rendered block list — add / reorder (native HTML5
 * drag-and-drop plus keyboard-accessible move buttons) / delete / edit —
 * driven entirely over Livewire, and the HTML is regenerated server-side by
 * BasicBlockRenderer on every change. Always available.
 */
class BasicDriver implements BuilderDriver
{
    public function key(): TemplateBuilder
    {
        return TemplateBuilder::Basic;
    }

    public function label(): string
    {
        return __('templates.builders.basic');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function editUrl(EmailTemplate $template): string
    {
        return EmailTemplateResource::getUrl('basic', ['record' => $template], panel: 'customer');
    }
}
