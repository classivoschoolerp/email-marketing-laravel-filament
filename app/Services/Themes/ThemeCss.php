<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Services\Branding\Brand;
use Throwable;

/**
 * Turns a resolved {@see Theme} + {@see Brand} into the CSS a page injects
 * (spec §6.3 F-6.3-001/003/006).
 *
 * Filament note (F-6.3-006): the theme resolver swaps the CSS custom
 * properties and the brand colours rather than swapping a compiled Filament
 * stylesheet. Filament v3's own palette is generated at build time from
 * `->colors()` in the panel provider, so a *runtime* per-user theme cannot be
 * a different compiled bundle — it has to be variables layered on top. That
 * is exactly what this emits: `:root` tokens plus the handful of Filament
 * surface rules that consume them, so a user's choice applies on the next
 * page load with no build step and no per-tenant asset pipeline.
 *
 * Everything interpolated here is either a literal hex colour (validated at
 * the model/reader boundary) or an operator-authored stylesheet from a theme
 * package. Package CSS is echoed verbatim by design — it is the same trust
 * level as the §3.25.8 custom-CSS box — but the closing-tag sequence is
 * neutralised so a stylesheet can never break out of its own <style> element
 * and become markup.
 */
final class ThemeCss
{
    /**
     * The <style> block for a theme + brand, or '' when there is nothing to
     * say. Callers concatenate this unconditionally.
     */
    public function styleTag(Theme $theme, ?Brand $brand = null, string $area = 'frontend'): string
    {
        $css = $this->variables($theme, $brand)
            .$this->surfaceRules()
            .$this->packageCss($theme);

        if (trim($css) === '') {
            return '';
        }

        return '<style data-theme="'.e($theme->slug).'" data-theme-area="'.e($area).'">'
            .$this->neutralise($css)
            .'</style>';
    }

    /**
     * `:root` custom properties: the theme palette, then the brand colours on
     * top so an operator's primary colour beats the theme's.
     */
    public function variables(Theme $theme, ?Brand $brand = null): string
    {
        $tokens = $theme->palette;

        if ($brand?->primaryColor !== null) {
            $tokens['primary'] = $brand->primaryColor;
        }

        if ($brand?->secondaryColor !== null) {
            $tokens['accent'] = $brand->secondaryColor;
        }

        $declarations = '';

        foreach ($tokens as $name => $value) {
            if (! in_array((string) $name, ThemePalettes::TOKENS, true)) {
                continue;
            }

            $value = trim((string) $value);

            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) !== 1) {
                continue;
            }

            $declarations .= '--emp-'.str_replace('_', '-', (string) $name).':'.$value.';';
        }

        if ($declarations === '') {
            return '';
        }

        $scheme = $theme->scheme === 'dark' ? 'dark' : 'light';

        return ':root{'.$declarations.'color-scheme:'.$scheme.';}';
    }

    /**
     * The minimum set of rules that make the tokens visible. Deliberately
     * small: a theme changes colour, not layout, so nothing here moves or
     * resizes a Filament component.
     */
    public function surfaceRules(): string
    {
        return '.fi-btn-color-primary,.fi-badge-color-primary{--emp-applied:1;}'
            .'.fi-sidebar,.fi-topbar>nav{border-color:var(--emp-border,currentColor);}'
            .'.fi-sidebar-item-active .fi-sidebar-item-button{background-color:var(--emp-surface-alt,transparent);}'
            .'.emp-brand-primary{color:var(--emp-primary,inherit);}'
            .'.emp-brand-bg{background-color:var(--emp-primary,transparent);color:var(--emp-primary-contrast,#fff);}';
    }

    /**
     * The uploaded package's stylesheet, size-capped.
     *
     * NOTHING is executed: the file is read with file_get_contents and
     * emitted as text. A theme package cannot contain PHP or JS in the first
     * place (config/emp-themes.php blocked extensions), and even if a file
     * slipped through it would only ever be echoed, never included.
     */
    public function packageCss(Theme $theme): string
    {
        $path = $theme->stylesheetPath();

        if ($path === null) {
            return '';
        }

        $maxBytes = max(1, (int) config('emp-themes.limits.max_stylesheet_kb', 512)) * 1024;

        try {
            if (filesize($path) > $maxBytes) {
                return '';
            }

            $contents = file_get_contents($path, false, null, 0, $maxBytes);
        } catch (Throwable) {
            return '';
        }

        return $contents === false ? '' : $contents;
    }

    /**
     * A stylesheet containing "</style" would end the element and everything
     * after it would be parsed as HTML. Break the sequence without altering
     * any CSS that does not contain it. (Same fix WP-34 applies to landing
     * page custom code.)
     */
    private function neutralise(string $css): string
    {
        return (string) preg_replace('#</\s*(style|script)#i', '<\\/$1', $css);
    }
}
