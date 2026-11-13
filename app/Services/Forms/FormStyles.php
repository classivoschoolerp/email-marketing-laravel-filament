<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVariant;

/**
 * Styling editor document + its CSS projection (spec F-2.7-007).
 *
 * Only literal, validated values reach the stylesheet: colours must match
 * #rgb/#rrggbb, sizes are integers clamped to a sane range, the font family
 * comes from a fixed stack list and the shadow from a fixed preset. A
 * customer-authored string is never interpolated raw into CSS — the styling
 * editor is not a CSS injection point (same rule D-82 applied to plan
 * branding).
 */
final class FormStyles
{
    /** Named font stacks (F-2.7-007 "fonts"). */
    public const FONTS = [
        'system' => 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif',
        'sans' => 'Helvetica,Arial,sans-serif',
        'serif' => 'Georgia,"Times New Roman",serif',
        'mono' => 'ui-monospace,SFMono-Regular,Menlo,monospace',
        'rounded' => '"Nunito","Trebuchet MS",sans-serif',
    ];

    /** Named shadow presets (F-2.7-007 "shadow"). */
    public const SHADOWS = [
        'none' => 'none',
        'sm' => '0 1px 2px rgba(0,0,0,.08)',
        'md' => '0 6px 20px rgba(0,0,0,.14)',
        'lg' => '0 18px 45px rgba(0,0,0,.22)',
    ];

    public const IMAGE_POSITIONS = ['none', 'left', 'right', 'top'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'background_color' => '#ffffff',
            'text_color' => '#111827',
            'button_color' => '#4f46e5',
            'button_text_color' => '#ffffff',
            'overlay_color' => '#0f172a',
            'font' => 'system',
            'corner_radius' => 10,
            'shadow' => 'md',
            'width' => 480,
            'image_url' => null,
            'image_position' => 'none',
        ];
    }

    /**
     * The styling document in force. An A/B arm (F-2.7-013) layers its own
     * non-empty keys over the form's, so an arm testing one button colour does
     * not have to restate the whole document.
     *
     * @return array<string, mixed>
     */
    public static function for(Form $form, ?FormVariant $variant = null): array
    {
        return self::normalize($variant === null
            ? (array) $form->styles
            : $variant->effectiveStyles($form));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $defaults = self::defaults();

        $font = (string) ($raw['font'] ?? $defaults['font']);
        $shadow = (string) ($raw['shadow'] ?? $defaults['shadow']);
        $imagePosition = (string) ($raw['image_position'] ?? 'none');
        $imageUrl = trim((string) ($raw['image_url'] ?? ''));

        return [
            'background_color' => self::color($raw['background_color'] ?? null, $defaults['background_color']),
            'text_color' => self::color($raw['text_color'] ?? null, $defaults['text_color']),
            'button_color' => self::color($raw['button_color'] ?? null, $defaults['button_color']),
            'button_text_color' => self::color($raw['button_text_color'] ?? null, $defaults['button_text_color']),
            'overlay_color' => self::color($raw['overlay_color'] ?? null, $defaults['overlay_color']),
            'font' => isset(self::FONTS[$font]) ? $font : 'system',
            'corner_radius' => min(48, max(0, (int) ($raw['corner_radius'] ?? $defaults['corner_radius']))),
            'shadow' => isset(self::SHADOWS[$shadow]) ? $shadow : 'md',
            'width' => min(1200, max(240, (int) ($raw['width'] ?? $defaults['width']))),
            'image_url' => self::url($imageUrl),
            'image_position' => in_array($imagePosition, self::IMAGE_POSITIONS, true) ? $imagePosition : 'none',
        ];
    }

    /**
     * The scoped stylesheet for one form. Scoped by uid so several forms can
     * coexist on the same host page without bleeding into each other or into
     * the host site's CSS.
     */
    public static function css(Form $form, ?FormVariant $variant = null): string
    {
        $s = self::for($form, $variant);
        $scope = '.emp-form-'.self::scope($form);

        return implode('', [
            $scope.'{',
            'background:'.$s['background_color'].';',
            'color:'.$s['text_color'].';',
            'font-family:'.self::FONTS[$s['font']].';',
            'border-radius:'.$s['corner_radius'].'px;',
            'box-shadow:'.self::SHADOWS[$s['shadow']].';',
            'max-width:'.$s['width'].'px;',
            'padding:24px;box-sizing:border-box;width:100%;',
            '}',
            $scope.' .emp-form__label{display:block;font-size:13px;font-weight:600;margin-bottom:4px}',
            $scope.' .emp-form__field{margin-bottom:14px}',
            $scope.' input,'.$scope.' select,'.$scope.' textarea{width:100%;padding:9px 11px;border:1px solid rgba(0,0,0,.18);'
                .'border-radius:'.max(2, (int) $s['corner_radius'] - 4).'px;font:inherit;box-sizing:border-box}',
            $scope.' .emp-form__submit{background:'.$s['button_color'].';color:'.$s['button_text_color'].';',
            'border:0;border-radius:'.max(2, (int) $s['corner_radius'] - 4).'px;padding:11px 20px;font:inherit;font-weight:600;cursor:pointer;width:100%}',
            $scope.' .emp-form__error{color:#b91c1c;font-size:12px;margin-top:4px}',
            $scope.' .emp-form__hint{font-size:12px;opacity:.7}',
            $scope.' .emp-form__panel{display:flex;gap:20px;align-items:stretch}',
            $scope.' .emp-form__image{background-size:cover;background-position:center;border-radius:'.max(2, (int) $s['corner_radius'] - 2).'px;min-height:160px;flex:1 1 40%}',
            $scope.' .emp-form__body{flex:1 1 60%}',
            $scope.'-close{position:absolute;top:8px;right:10px;background:none;border:0;font-size:20px;line-height:1;cursor:pointer;color:inherit;opacity:.6}',

            // F-2.7-004. Deliberately NO rule that hides a step: every step is
            // visible until the step script pages them, so a visitor without
            // JavaScript reaches every input (see FormRenderer's docblock).
            $scope.' .emp-form__step{border:0;margin:0;padding:0;min-width:0}',
            $scope.' .emp-form__step-title{font-size:15px;font-weight:600;padding:0;margin:0 0 6px}',
            $scope.' .emp-form__step-description{font-size:13px;opacity:.8;margin:0 0 12px}',
            $scope.' .emp-form__nav{display:flex;gap:8px;margin-bottom:14px}',
            $scope.' .emp-form__next,'.$scope.' .emp-form__back{flex:1 1 auto;border:0;font:inherit;font-weight:600;cursor:pointer;'
                .'padding:11px 20px;border-radius:'.max(2, (int) $s['corner_radius'] - 4).'px}',
            $scope.' .emp-form__next{background:'.$s['button_color'].';color:'.$s['button_text_color'].'}',
            $scope.' .emp-form__back{background:transparent;color:inherit;box-shadow:inset 0 0 0 1px rgba(0,0,0,.18)}',
            $scope.' .emp-form__progress{margin:0 0 16px}',
            $scope.' .emp-form__progress-track{height:6px;border-radius:3px;background:rgba(0,0,0,.10);overflow:hidden}',
            $scope.' .emp-form__progress-bar{display:block;height:100%;background:'.$s['button_color'].';transition:width .2s ease}',
            $scope.' .emp-form__progress-label{font-size:12px;opacity:.7;margin-top:6px}',
        ]);
    }

    /** Stable CSS scope token for a form (uid without dashes). */
    public static function scope(Form $form): string
    {
        return preg_replace('/[^a-z0-9]/i', '', (string) $form->uid) ?: 'form';
    }

    private static function color(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1 ? $value : $fallback;
    }

    private static function url(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return preg_match('~^https?://~i', $value) === 1 ? $value : null;
    }

    /** @return array<string, string> */
    public static function fontOptions(): array
    {
        $options = [];

        foreach (array_keys(self::FONTS) as $key) {
            $options[$key] = __('forms.styles.font_'.$key);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function shadowOptions(): array
    {
        $options = [];

        foreach (array_keys(self::SHADOWS) as $key) {
            $options[$key] = __('forms.styles.shadow_'.$key);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function imagePositionOptions(): array
    {
        $options = [];

        foreach (self::IMAGE_POSITIONS as $key) {
            $options[$key] = __('forms.styles.image_'.$key);
        }

        return $options;
    }
}
