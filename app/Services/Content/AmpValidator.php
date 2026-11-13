<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * AMP for Email validator (spec §2.5.4 F-2.5.4-014, WP-46).
 *
 * Implements the AMP for Email (amp4email) format requirements as published by
 * the AMP project and enforced by Gmail's dynamic-mail pipeline. It is a
 * STATIC validator: it checks the document's required boilerplate, its allowed
 * tag/attribute surface and its size. It is deliberately NOT a re-implementation
 * of amphtml's full validator — that is a 100 kB machine-generated ruleset —
 * and it says so rather than claiming AMP conformance it cannot prove. What it
 * does guarantee is that a document it rejects would certainly be rejected by
 * a real AMP validator too, so nothing it lets through is worse than what the
 * customer wrote.
 *
 * ===================== REQUIRED, all errors =====================
 *   doctype        `<!doctype html>` (case-insensitive) as the first markup
 *   amp4email      `<html ⚡4email>` or `<html amp4email>`
 *   charset        `<meta charset="utf-8">` in <head>
 *   runtime        `<script async src="https://cdn.ampproject.org/v0.js">`
 *   boilerplate    `<style amp4email-boilerplate>body{visibility:hidden}</style>`
 *   body           a <body> element
 *
 * ===================== FORBIDDEN, all errors ====================
 *   any <script> other than the AMP runtime, an official
 *     https://cdn.ampproject.org/v0/amp-*.js component script, or
 *     <script type="application/json"> component configuration
 *   <link rel="stylesheet"> to anything but the AMP font endpoint, and any
 *     @import — AMP4EMAIL has no external stylesheets at all
 *   a <style> element that is neither amp4email-boilerplate nor amp-custom
 *   !important anywhere in amp-custom CSS
 *   the tags AMP4EMAIL prohibits outright ({@see FORBIDDEN_TAGS})
 *   an amp-* component outside {@see ALLOWED_COMPONENTS}
 *   an allowed component used without its <script custom-element> declaration
 *   a resource loaded over plain http://
 *   amp-custom CSS over {@see MAX_CUSTOM_CSS_BYTES}
 *   a document over {@see MAX_DOCUMENT_BYTES}
 *
 * Gmail additionally recommends keeping the AMP part under 100 kB; that is a
 * WARNING here, not an error, because it is a vendor guideline rather than the
 * format specification.
 */
final class AmpValidator
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    /** AMP for Email document size limit. */
    public const MAX_DOCUMENT_BYTES = 200000;

    /** Gmail's recommended ceiling for the AMP part. */
    public const GMAIL_SOFT_LIMIT_BYTES = 100000;

    /** `<style amp-custom>` size limit. */
    public const MAX_CUSTOM_CSS_BYTES = 75000;

    public const RUNTIME_SRC = 'https://cdn.ampproject.org/v0.js';

    public const COMPONENT_SRC_PREFIX = 'https://cdn.ampproject.org/v0/';

    /** The AMP font endpoint is the one stylesheet AMP4EMAIL permits. */
    public const ALLOWED_STYLESHEET_HOSTS = ['fonts.googleapis.com'];

    /**
     * Components AMP for Email allows. Anything else (amp-video, amp-iframe,
     * amp-analytics, amp-ad …) is rejected by the format, not by us.
     *
     * @var list<string>
     */
    public const ALLOWED_COMPONENTS = [
        'amp-accordion', 'amp-anim', 'amp-autocomplete', 'amp-bind-macro',
        'amp-carousel', 'amp-fit-text', 'amp-form', 'amp-image-lightbox',
        'amp-img', 'amp-layout', 'amp-lightbox', 'amp-list', 'amp-mustache',
        'amp-selector', 'amp-sidebar', 'amp-state', 'amp-timeago',
    ];

    /**
     * Components that need no `<script custom-element>` because the runtime
     * ships them (amp-img/amp-layout) or because they are declared as
     * custom-template (amp-mustache) / by another component.
     *
     * @var list<string>
     */
    public const BUILTIN_COMPONENTS = ['amp-img', 'amp-layout', 'amp-anim', 'amp-pixel'];

    /** @var list<string> */
    public const FORBIDDEN_TAGS = [
        'applet', 'audio', 'base', 'embed', 'frame', 'frameset', 'iframe',
        'object', 'param', 'video',
    ];

    /**
     * Structured findings, empty when the document is acceptable.
     *
     * @return list<array{rule: string, severity: string, detail: ?string}>
     */
    public function findings(?string $amp): array
    {
        $amp = (string) $amp;

        if (trim($amp) === '') {
            return [];
        }

        $findings = [];
        $head = $this->section($amp, 'head');

        /* ---- required boilerplate -------------------------------- */

        if (preg_match('/^\s*(?:<!--.*?-->\s*)*<!doctype\s+html\s*>/is', $amp) !== 1) {
            $findings[] = $this->finding('missing_doctype');
        }

        if (preg_match('/<html\b[^>]*>/i', $amp, $html) !== 1) {
            $findings[] = $this->finding('missing_html');
        } elseif (preg_match('/(?:\x{26A1}|&#9889;|&#x26a1;|\bamp4email\b)4?email|\bamp4email\b/iu', $html[0]) !== 1) {
            $findings[] = $this->finding('missing_amp4email', $this->truncate($html[0]));
        }

        if (preg_match('/<meta[^>]*\bcharset\s*=\s*["\']?utf-8/i', $head) !== 1) {
            $findings[] = $this->finding('missing_charset');
        }

        if (! str_contains($amp, self::RUNTIME_SRC)) {
            $findings[] = $this->finding('missing_runtime', self::RUNTIME_SRC);
        }

        if (preg_match('/<style\b[^>]*\bamp4email-boilerplate\b[^>]*>\s*body\s*\{\s*visibility\s*:\s*hidden\s*;?\s*\}\s*<\/style>/i', $amp) !== 1) {
            $findings[] = $this->finding('missing_boilerplate');
        }

        if (preg_match('/<body\b/i', $amp) !== 1) {
            $findings[] = $this->finding('missing_body');
        }

        /* ---- scripts --------------------------------------------- */

        preg_match_all('/<script\b([^>]*)>/i', $amp, $scripts);

        foreach ($scripts[1] ?? [] as $attributes) {
            if (preg_match('/\btype\s*=\s*["\']application\/json["\']/i', $attributes) === 1) {
                continue; // component configuration — allowed.
            }

            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attributes, $src) !== 1) {
                $findings[] = $this->finding('inline_script', $this->truncate('<script'.$attributes.'>'));

                continue;
            }

            $url = trim($src[1]);

            if ($url !== self::RUNTIME_SRC && ! str_starts_with($url, self::COMPONENT_SRC_PREFIX)) {
                $findings[] = $this->finding('foreign_script', $url);
            }
        }

        /* ---- stylesheets and styles ------------------------------ */

        preg_match_all('/<link\b[^>]*>/i', $amp, $links);

        foreach ($links[0] ?? [] as $link) {
            if (preg_match('/\brel\s*=\s*["\']?stylesheet/i', $link) !== 1) {
                continue;
            }

            $host = preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $link, $href) === 1
                ? (string) parse_url(trim($href[1]), PHP_URL_HOST)
                : '';

            if (! in_array(mb_strtolower($host), self::ALLOWED_STYLESHEET_HOSTS, true)) {
                $findings[] = $this->finding('external_stylesheet', $this->truncate($link));
            }
        }

        preg_match_all('/<style\b([^>]*)>(.*?)<\/style>/is', $amp, $styles, PREG_SET_ORDER);

        $customCss = '';

        foreach ($styles as [$whole, $attributes, $css]) {
            $isBoilerplate = preg_match('/\bamp4email-boilerplate\b/i', $attributes) === 1;
            $isCustom = preg_match('/\bamp-custom\b/i', $attributes) === 1;

            if (! $isBoilerplate && ! $isCustom) {
                $findings[] = $this->finding('foreign_style', $this->truncate('<style'.$attributes.'>'));

                continue;
            }

            if ($isCustom) {
                $customCss .= $css;
            }
        }

        if (stripos($customCss, '@import') !== false) {
            $findings[] = $this->finding('css_import');
        }

        if (preg_match('/!\s*important/i', $customCss) === 1) {
            $findings[] = $this->finding('css_important');
        }

        if (strlen($customCss) > self::MAX_CUSTOM_CSS_BYTES) {
            $findings[] = $this->finding('custom_css_too_large', (string) strlen($customCss));
        }

        /* ---- tags and components --------------------------------- */

        foreach (self::FORBIDDEN_TAGS as $tag) {
            if (preg_match('/<'.$tag.'\b/i', $amp) === 1) {
                $findings[] = $this->finding('forbidden_tag', $tag);
            }
        }

        // A plain <form> must be <form> WITH amp-form loaded; without the
        // component script AMP rejects it, which the component check below
        // catches. A bare <input type="image"> is never allowed.
        if (preg_match('/<input\b[^>]*\btype\s*=\s*["\']image["\']/i', $amp) === 1) {
            $findings[] = $this->finding('forbidden_tag', 'input[type=image]');
        }

        preg_match_all('/<(amp-[a-z0-9-]+)\b/i', $amp, $components);

        $used = array_values(array_unique(array_map(mb_strtolower(...), $components[1] ?? [])));

        foreach ($used as $component) {
            if (! in_array($component, self::ALLOWED_COMPONENTS, true)) {
                $findings[] = $this->finding('forbidden_component', $component);

                continue;
            }

            if (in_array($component, self::BUILTIN_COMPONENTS, true)) {
                continue;
            }

            if (! str_contains($amp, self::COMPONENT_SRC_PREFIX.$component.'-')) {
                $findings[] = $this->finding('missing_component_script', $component);
            }
        }

        /* ---- transport and size ---------------------------------- */

        preg_match_all('/\b(?:src|href)\s*=\s*["\'](http:\/\/[^"\']+)["\']/i', $amp, $insecure);

        foreach (array_slice(array_unique($insecure[1] ?? []), 0, 5) as $url) {
            $findings[] = $this->finding('insecure_url', $url);
        }

        $bytes = strlen($amp);

        if ($bytes > self::MAX_DOCUMENT_BYTES) {
            $findings[] = $this->finding('document_too_large', (string) $bytes);
        } elseif ($bytes > self::GMAIL_SOFT_LIMIT_BYTES) {
            $findings[] = $this->finding('gmail_size_warning', (string) $bytes, self::SEVERITY_WARNING);
        }

        return $findings;
    }

    /** True when the document may be attached as a text/x-amp-html part. */
    public function valid(?string $amp): bool
    {
        if (trim((string) $amp) === '') {
            return false;
        }

        foreach ($this->findings($amp) as $finding) {
            if ($finding['severity'] === self::SEVERITY_ERROR) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> human error messages, for a form validator */
    public function errors(?string $amp): array
    {
        $messages = [];

        foreach ($this->findings($amp) as $finding) {
            if ($finding['severity'] !== self::SEVERITY_ERROR) {
                continue;
            }

            $messages[] = __('templates.amp.rules.'.$finding['rule'])
                .($finding['detail'] !== null ? ' — '.$finding['detail'] : '');
        }

        return $messages;
    }

    /**
     * A minimal, valid amp4email skeleton the editor can start people from.
     * Everything in it is required by the format; nothing is decoration.
     */
    public function skeleton(): string
    {
        return <<<'AMP'
            <!doctype html>
            <html ⚡4email>
            <head>
              <meta charset="utf-8">
              <script async src="https://cdn.ampproject.org/v0.js"></script>
              <style amp4email-boilerplate>body{visibility:hidden}</style>
              <style amp-custom>
                body { font-family: Arial, sans-serif; color: #111111; }
              </style>
            </head>
            <body>
              <p>Hello {FNAME|there},</p>
            </body>
            </html>
            AMP;
    }

    /* ---------------------------------------------------------------- */

    /** @return array{rule: string, severity: string, detail: ?string} */
    private function finding(string $rule, ?string $detail = null, string $severity = self::SEVERITY_ERROR): array
    {
        return ['rule' => $rule, 'severity' => $severity, 'detail' => $detail];
    }

    private function section(string $html, string $tag): string
    {
        return preg_match('/<'.$tag.'\b[^>]*>(.*?)<\/'.$tag.'>/is', $html, $match) === 1 ? $match[1] : $html;
    }

    private function truncate(string $value, int $limit = 120): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }
}
