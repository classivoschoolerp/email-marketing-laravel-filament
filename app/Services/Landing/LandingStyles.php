<?php

declare(strict_types=1);

namespace App\Services\Landing;

/**
 * The base stylesheet every landing page ships with (spec §2.6 F-2.6-002,
 * §2.6 F-2.6-013 mobile responsiveness).
 *
 * A landing page is served as a STANDALONE document — it must not depend on
 * the app's Vite bundle, because it can answer on a customer's own domain
 * where that bundle is not the right thing to load. So the classes emitted by
 * {@see LandingBlocks} are backed by this small, literal stylesheet, which is
 * injected into the document head by {@see LandingPageRenderer} and into the
 * builder canvas by the builder page.
 *
 * Every value here is a literal — nothing customer-supplied is interpolated
 * (D-100). Customer CSS is a separate, sanitised <style> block that comes
 * AFTER this one, so it can override anything without being able to escape
 * the element.
 */
final class LandingStyles
{
    /** Phone breakpoint — the same 600px the email builder uses. */
    public const BREAKPOINT = 600;

    public static function css(): string
    {
        return <<<'CSS'
*,*::before,*::after{box-sizing:border-box}
body.lp-body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;line-height:1.6;color:#111827;background:#ffffff}
.lp-container{max-width:1080px;margin:0 auto;padding:0 20px}
.lp-section{padding:48px 0}
.lp-hero{padding:72px 0;background:#f8fafc;text-align:center}
.lp-h1{margin:0 0 16px;font-size:40px;line-height:1.15;font-weight:800}
.lp-h2{margin:0 0 12px;font-size:30px;line-height:1.2;font-weight:700}
.lp-h3{margin:0 0 8px;font-size:20px;line-height:1.3;font-weight:700}
.lp-lead{margin:0 0 24px;font-size:19px;color:#4b5563}
.lp-p{margin:0 0 16px;font-size:16px;color:#374151}
.lp-btn{display:inline-block;padding:14px 28px;border-radius:8px;background:#4f46e5;color:#ffffff;font-weight:600;text-decoration:none}
.lp-btn:hover{background:#4338ca}
.lp-image{display:block;max-width:100%;height:auto;margin:0 auto}
.lp-video{position:relative;padding-top:56.25%}
.lp-video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.lp-divider{border:0;border-top:1px solid #e5e7eb;margin:32px 0}
.lp-cols{display:flex;flex-wrap:wrap;gap:24px}
.lp-col,.lp-plan,.lp-testimonial{flex:1 1 260px;min-width:0}
.lp-plan{padding:24px;border:1px solid #e5e7eb;border-radius:12px;text-align:center}
.lp-plan__price{font-size:36px;font-weight:800;margin:8px 0 16px}
.lp-plan__features{list-style:none;margin:0 0 20px;padding:0;color:#4b5563}
.lp-plan__features li{padding:6px 0;border-bottom:1px solid #f3f4f6}
.lp-testimonial{margin:0;padding:24px;border-radius:12px;background:#f9fafb}
.lp-testimonial__by{margin-top:12px;font-size:14px;color:#6b7280}
.lp-faq{padding:12px 0;border-bottom:1px solid #e5e7eb}
.lp-faq summary{cursor:pointer;font-weight:600}
.lp-countdown{display:flex;flex-direction:column;align-items:center;gap:4px;padding:16px}
.lp-countdown__value{font-size:32px;font-weight:800;font-variant-numeric:tabular-nums}
.lp-form{max-width:520px;margin:0 auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff}
.lp-form .emp-form__field{margin-bottom:14px}
.lp-form label{display:block;margin-bottom:4px;font-size:14px;font-weight:600}
.lp-form input,.lp-form select,.lp-form textarea{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px}
.lp-form button{width:100%;padding:13px 20px;border:0;border-radius:8px;background:#4f46e5;color:#fff;font-size:16px;font-weight:600;cursor:pointer}
.lp-form__errors{color:#b91c1c;font-size:14px}
.lp-thanks{max-width:640px;margin:0 auto;padding:64px 20px;text-align:center}
.lp-error{color:#b91c1c;font-size:14px}
.lp-gate{max-width:420px;margin:24px auto 0;text-align:left}
@media only screen and (max-width:600px){
.lp-h1{font-size:30px}.lp-h2{font-size:24px}.lp-hero{padding:48px 0}.lp-section{padding:32px 0}
.lp-cols{flex-direction:column}
.hide-mobile{display:none!important;max-height:0!important;overflow:hidden!important}
}
CSS;
    }
}
