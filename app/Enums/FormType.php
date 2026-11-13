<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six form shapes of spec F-2.7-002. The value is also the CSS/behaviour
 * key the embed loader switches on, so adding a shape is one case here plus
 * one branch in the loader template.
 */
enum FormType: string
{
    case Embedded = 'embedded';
    case Popup = 'popup';
    case SlideIn = 'slide_in';
    case FloatingBar = 'floating_bar';
    case FullScreen = 'full_screen';
    case ExitIntent = 'exit_intent';

    public function label(): string
    {
        return __('forms.type.'.$this->value);
    }

    /** Shapes that render as an overlay rather than in the page flow. */
    public function isOverlay(): bool
    {
        return $this !== self::Embedded;
    }

    /**
     * Exit-intent is a shape AND a display rule: choosing it implies the
     * exit-intent trigger even when no rule was configured (F-2.7-005).
     */
    public function impliesExitIntent(): bool
    {
        return $this === self::ExitIntent;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
