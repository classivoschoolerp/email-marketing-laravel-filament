<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingPage;
use App\Services\Lists\PublicPageRenderer;

/**
 * Custom CSS/JS and third-party tracking scripts (spec §2.6 F-2.6-010).
 *
 * THE RULE (D-100): only literals reach the page. Two different problems, two
 * different answers:
 *
 *  - Custom CSS / JS is page CONTENT the customer authored for their own
 *    page. It is emitted verbatim inside <style>/<script>, with the closing
 *    tag sequence stripped so it cannot escape that element — the exact
 *    F-1.5-017 contract already implemented for list pages, reused here via
 *    {@see PublicPageRenderer::safeCss()} rather than re-derived.
 *
 *  - GA / GTM / Facebook Pixel are NOT snippets. The customer supplies an
 *    ID only; the vendor's loader is a literal string in this class with the
 *    ID interpolated after a strict pattern check. An ID that does not match
 *    its vendor's documented shape emits NOTHING — it is never "escaped and
 *    hoped for", because a pasted <script> in an ID field is an attack, not a
 *    typo, and half-loading a mangled tag would silently break analytics.
 *
 * The regexes are the vendors' documented shapes:
 *   GA4        G-XXXXXXXXXX          (also accepts UA-/AW-/GT- legacy ids)
 *   GTM        GTM-XXXXXXX
 *   FB Pixel   15–16 digits
 */
class LandingCustomCode
{
    public const GA_PATTERN = '/^(G|UA|AW|GT)-[A-Z0-9\-]{4,24}$/i';

    public const GTM_PATTERN = '/^GTM-[A-Z0-9]{4,12}$/i';

    public const PIXEL_PATTERN = '/^[0-9]{10,20}$/';

    public function __construct(private readonly PublicPageRenderer $pages) {}

    public function css(LandingPage $page): ?string
    {
        return $this->pages->safeCss($page->custom_css);
    }

    public function js(LandingPage $page): ?string
    {
        return $this->pages->safeJs($page->custom_js);
    }

    public static function validGaId(?string $id): bool
    {
        return is_string($id) && preg_match(self::GA_PATTERN, trim($id)) === 1;
    }

    public static function validGtmId(?string $id): bool
    {
        return is_string($id) && preg_match(self::GTM_PATTERN, trim($id)) === 1;
    }

    public static function validPixelId(?string $id): bool
    {
        return is_string($id) && preg_match(self::PIXEL_PATTERN, trim($id)) === 1;
    }

    /**
     * The <head> tracking tags for a page. Returns '' when nothing is
     * configured or nothing validates.
     */
    public function headScripts(LandingPage $page): string
    {
        return implode("\n", array_filter([
            $this->gtag((string) $page->ga_measurement_id),
            $this->gtm((string) $page->gtm_container_id),
            $this->pixel((string) $page->fb_pixel_id),
        ], static fn (string $tag): bool => $tag !== ''));
    }

    /** The <noscript> half of GTM, which belongs immediately after <body>. */
    public function bodyScripts(LandingPage $page): string
    {
        $id = trim((string) $page->gtm_container_id);

        if (! self::validGtmId($id)) {
            return '';
        }

        return '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id='.rawurlencode($id)
            .'" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
    }

    private function gtag(string $id): string
    {
        $id = trim($id);

        if (! self::validGaId($id)) {
            return '';
        }

        // $id is [A-Z0-9-] only at this point — nothing here can break out.
        return '<script async src="https://www.googletagmanager.com/gtag/js?id='.$id.'"></script>'."\n"
            .'<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
            ."gtag('js',new Date());gtag('config','".$id."');</script>";
    }

    private function gtm(string $id): string
    {
        $id = trim($id);

        if (! self::validGtmId($id)) {
            return '';
        }

        return '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
            .'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";'
            .'j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);'
            ."})(window,document,'script','dataLayer','".$id."');</script>";
    }

    private function pixel(string $id): string
    {
        $id = trim($id);

        if (! self::validPixelId($id)) {
            return '';
        }

        // Digits only at this point.
        return '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?'
            .'n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;'
            .'n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;'
            .'s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script",'
            .'"https://connect.facebook.net/en_US/fbevents.js");'
            ."fbq('init','".$id."');fbq('track','PageView');</script>"
            .'<noscript><img height="1" width="1" style="display:none" alt="" '
            .'src="https://www.facebook.com/tr?id='.$id.'&ev=PageView&noscript=1"></noscript>';
    }
}
