<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Models\EmailTemplate;

/**
 * A template editor implementation (spec §2.5.3, docs/ARCHITECTURE.md rule 2 — driver
 * registry from day one). New vendors (Stripo, Unlayer, ...) implement this
 * interface and are listed in config('emp-templates.builders'); no core change
 * is ever required to add one.
 */
interface BuilderDriver
{
    /** Stable driver key — must match a TemplateBuilder enum case. */
    public function key(): TemplateBuilder;

    public function label(): string;

    /**
     * Can the driver be offered right now? External builders return false
     * until their credentials are configured (e.g. Stripo plugin id/secret).
     */
    public function isAvailable(): bool;

    /** Absolute URL of the edit surface for the given template. */
    public function editUrl(EmailTemplate $template): string;
}
