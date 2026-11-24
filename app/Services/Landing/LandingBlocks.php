<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Services\Forms\FormRenderer;

/**
 * The landing-page block catalogue (spec §2.6 F-2.6-002).
 *
 * These are GrapesJS blocks, authored HERE rather than in JavaScript, for two
 * reasons: the labels have to go through `__()` (docs/ARCHITECTURE.md — no hardcoded
 * English anywhere), and the same markup seeds the starter designs in
 * {@see LandingTemplates}. The builder JS receives this list in its config
 * payload and registers each entry with `BlockManager.add()` — which is why
 * adding a landing block never touches a line of JavaScript and why no new
 * npm package was needed for the page builder.
 *
 * Markup convention, deliberately different from the email builder: plain
 * semantic HTML with a stylesheet, not tables with inlined styles. A landing
 * page is rendered by a browser, so the email-safety constraints that shape
 * `resources/js/template-builder.js`'s newsletter preset do not apply (the
 * builder switches juice inlining off in landing mode for the same reason).
 *
 * The FORM block is a placeholder, not markup: the real fields are produced
 * at render time by the §2.7 {@see FormRenderer}, so a
 * landing page can never drift from the form it embeds.
 */
final class LandingBlocks
{
    /** Marker element the renderer swaps for the embedded form's markup. */
    public const FORM_PLACEHOLDER_ATTRIBUTE = 'data-landing-form';

    /** Countdown element consumed by the renderer's countdown script. */
    public const COUNTDOWN_ATTRIBUTE = 'data-landing-countdown';

    public const CATEGORY_LAYOUT = 'layout';

    public const CATEGORY_CONTENT = 'content';

    public const CATEGORY_CONVERSION = 'conversion';

    /**
     * Every block, as the builder config consumes it.
     *
     * @return list<array{id:string, label:string, category:string, icon:string, content:string}>
     */
    public static function all(): array
    {
        $blocks = [];

        foreach (self::definitions() as $id => $definition) {
            $blocks[] = [
                'id' => $id,
                'label' => __('landing.blocks.'.$id),
                'category' => __('landing.block_categories.'.$definition['category']),
                'icon' => $definition['icon'],
                'content' => $definition['content'],
            ];
        }

        return $blocks;
    }

    /** The raw HTML of one block, for the starter designs. */
    public static function html(string $id): string
    {
        return self::definitions()[$id]['content'] ?? '';
    }

    /**
     * @return array<string, array{category:string, icon:string, content:string}>
     */
    private static function definitions(): array
    {
        return [
            'section' => [
                'category' => self::CATEGORY_LAYOUT,
                'icon' => 'gjs-fonts gjs-f-b1',
                'content' => '<section class="lp-section"><div class="lp-container">'
                    .'<h2 class="lp-h2">'.e(__('landing.blocks_content.section_heading')).'</h2>'
                    .'<p class="lp-p">'.e(__('landing.blocks_content.section_body')).'</p>'
                    .'</div></section>',
            ],

            'hero' => [
                'category' => self::CATEGORY_LAYOUT,
                'icon' => 'gjs-fonts gjs-f-b1',
                'content' => '<section class="lp-hero"><div class="lp-container">'
                    .'<h1 class="lp-h1">'.e(__('landing.blocks_content.hero_heading')).'</h1>'
                    .'<p class="lp-lead">'.e(__('landing.blocks_content.hero_body')).'</p>'
                    .'<a class="lp-btn" href="#lp-form">'.e(__('landing.blocks_content.hero_cta')).'</a>'
                    .'</div></section>',
            ],

            'columns' => [
                'category' => self::CATEGORY_LAYOUT,
                'icon' => 'gjs-fonts gjs-f-b3',
                'content' => '<section class="lp-section"><div class="lp-container lp-cols">'
                    .'<div class="lp-col"><h3 class="lp-h3">'.e(__('landing.blocks_content.column_heading')).'</h3>'
                    .'<p class="lp-p">'.e(__('landing.blocks_content.column_body')).'</p></div>'
                    .'<div class="lp-col"><h3 class="lp-h3">'.e(__('landing.blocks_content.column_heading')).'</h3>'
                    .'<p class="lp-p">'.e(__('landing.blocks_content.column_body')).'</p></div>'
                    .'</div></section>',
            ],

            'spacer' => [
                'category' => self::CATEGORY_LAYOUT,
                'icon' => 'gjs-fonts gjs-f-divider',
                'content' => '<div class="lp-spacer" style="height:48px"></div>',
            ],

            'divider' => [
                'category' => self::CATEGORY_LAYOUT,
                'icon' => 'gjs-fonts gjs-f-divider',
                'content' => '<hr class="lp-divider">',
            ],

            'heading' => [
                'category' => self::CATEGORY_CONTENT,
                'icon' => 'gjs-fonts gjs-f-h1p',
                'content' => '<h2 class="lp-h2">'.e(__('landing.blocks_content.section_heading')).'</h2>',
            ],

            'text' => [
                'category' => self::CATEGORY_CONTENT,
                'icon' => 'gjs-fonts gjs-f-text',
                'content' => '<p class="lp-p">'.e(__('landing.blocks_content.section_body')).'</p>',
            ],

            'image' => [
                'category' => self::CATEGORY_CONTENT,
                'icon' => 'gjs-fonts gjs-f-image',
                'content' => '<img class="lp-image" src="" alt="">',
            ],

            'video' => [
                'category' => self::CATEGORY_CONTENT,
                'icon' => 'gjs-fonts gjs-f-video',
                'content' => '<div class="lp-video"><iframe src="" title="'
                    .e(__('landing.blocks_content.video_title')).'" allowfullscreen loading="lazy"></iframe></div>',
            ],

            'faq' => [
                'category' => self::CATEGORY_CONTENT,
                'icon' => 'gjs-fonts gjs-f-text',
                'content' => '<section class="lp-section"><div class="lp-container">'
                    .'<details class="lp-faq"><summary>'.e(__('landing.blocks_content.faq_question')).'</summary>'
                    .'<p class="lp-p">'.e(__('landing.blocks_content.faq_answer')).'</p></details>'
                    .'</div></section>',
            ],

            'html' => [
                'category' => self::CATEGORY_CONTENT,
                'icon' => 'gjs-fonts gjs-f-code',
                'content' => '<div data-raw-html="1"></div>',
            ],

            /* ---- conversion blocks (the §2.6 headline list) ------------- */

            'form' => [
                'category' => self::CATEGORY_CONVERSION,
                'icon' => 'gjs-fonts gjs-f-b1',
                'content' => '<div id="lp-form" '.self::FORM_PLACEHOLDER_ATTRIBUTE.'="1"></div>',
            ],

            'button' => [
                'category' => self::CATEGORY_CONVERSION,
                'icon' => 'gjs-fonts gjs-f-button',
                'content' => '<a class="lp-btn" href="#lp-form">'
                    .e(__('landing.blocks_content.hero_cta')).'</a>',
            ],

            'countdown' => [
                'category' => self::CATEGORY_CONVERSION,
                'icon' => 'gjs-fonts gjs-f-text',
                'content' => '<div class="lp-countdown" '.self::COUNTDOWN_ATTRIBUTE.'="1" data-deadline="">'
                    .'<span class="lp-countdown__label">'.e(__('landing.blocks_content.countdown_label')).'</span>'
                    .'<span class="lp-countdown__value">--:--:--</span></div>',
            ],

            'testimonials' => [
                'category' => self::CATEGORY_CONVERSION,
                'icon' => 'gjs-fonts gjs-f-quo',
                'content' => '<section class="lp-section"><div class="lp-container lp-cols">'
                    .self::testimonial().self::testimonial()
                    .'</div></section>',
            ],

            'pricing' => [
                'category' => self::CATEGORY_CONVERSION,
                'icon' => 'gjs-fonts gjs-f-b3',
                'content' => '<section class="lp-section"><div class="lp-container lp-cols">'
                    .self::pricingPlan(__('landing.blocks_content.pricing_starter'), '19')
                    .self::pricingPlan(__('landing.blocks_content.pricing_pro'), '49')
                    .'</div></section>',
            ],
        ];
    }

    private static function testimonial(): string
    {
        return '<figure class="lp-testimonial">'
            .'<blockquote class="lp-p">'.e(__('landing.blocks_content.testimonial_quote')).'</blockquote>'
            .'<figcaption class="lp-testimonial__by">'.e(__('landing.blocks_content.testimonial_by')).'</figcaption>'
            .'</figure>';
    }

    private static function pricingPlan(string $name, string $price): string
    {
        return '<div class="lp-plan">'
            .'<h3 class="lp-h3">'.e($name).'</h3>'
            .'<div class="lp-plan__price">'.e($price).'</div>'
            .'<ul class="lp-plan__features">'
            .'<li>'.e(__('landing.blocks_content.pricing_feature')).'</li>'
            .'<li>'.e(__('landing.blocks_content.pricing_feature')).'</li>'
            .'</ul>'
            .'<a class="lp-btn" href="#lp-form">'.e(__('landing.blocks_content.pricing_cta')).'</a>'
            .'</div>';
    }
}
