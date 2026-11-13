<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Template builder identifiers (spec §2.5.3). Values are the stable driver
 * keys used by the BuilderRegistry — one enum case per BuilderDriver.
 * GrapesJS is the P1 drag-and-drop builder (docs/DECISIONS.md D-11); Stripo and
 * Unlayer plug in through the same registry once credentials exist.
 */
enum TemplateBuilder: string
{
    case GrapesJs = 'grapesjs';
    /** WP-27B (F-2.5.3-001): the simple built-in block builder. */
    case Basic = 'basic';
    case Html = 'html';
    case RichText = 'richtext';
    case Stripo = 'stripo';
    case Unlayer = 'unlayer';

    public function label(): string
    {
        return __('templates.builders.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
