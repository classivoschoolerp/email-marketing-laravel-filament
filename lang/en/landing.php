<?php

declare(strict_types=1);

/*
 * Landing pages (spec §2.6) and their public render (§1.8) — WP-34.
 *
 * Everything the module shows, in one place: no English is hardcoded in a
 * view, a Filament label, a builder block or a public page (docs/ARCHITECTURE.md).
 * `blocks_content` deserves a note — those strings are the placeholder COPY
 * inside a dragged block, so they are translated for the same reason the
 * labels are: a French customer should not have to delete English lorem.
 */

return [

    'resource' => [
        'navigation_group' => 'Content',
        'navigation_label' => 'Landing pages',
        'label' => 'Landing page',
        'plural_label' => 'Landing pages',
    ],

    'copy_suffix' => '(copy)',

    'status' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'unpublished' => 'Unpublished',
    ],

    'tabs' => [
        'general' => 'General',
        'domain' => 'Domain',
        'seo' => 'SEO',
        'form' => 'Form & conversion',
        'code' => 'Custom code & tracking',
        'publish' => 'Publishing',
    ],

    'fields' => [
        'name' => 'Name',
        'slug' => 'URL slug',
        'slug_help' => 'The public address is /p/{slug}. It must be unique across the platform, so a taken slug is adjusted on save.',
        'status' => 'Status',
        'created_at' => 'Created',
        'updated_at' => 'Last edited',

        'tracking_domain' => 'Custom domain',
        'tracking_domain_help' => 'Choose one of your verified tracking domains (§2.9.8). Unverified domains are listed but the page keeps answering on the platform URL until the DNS check passes.',
        'tracking_domain_none' => 'Platform domain',
        'subdomain' => 'Subdomain',
        'subdomain_help' => 'A single label, e.g. "offers" for offers.:host. Used only when no custom domain is assigned.',
        'ssl_status' => 'SSL',
        'public_url' => 'Public URL',

        'seo_title' => 'Page title',
        'seo_title_help' => 'Used for the browser tab, the search result and the share preview. Falls back to the page name.',
        'seo_description' => 'Meta description',
        'og_image_url' => 'Social share image (OG image)',
        'canonical_url' => 'Canonical URL',
        'canonical_help' => 'Leave empty to point at this page itself. Set it when the same content also lives on your own site.',
        'favicon_url' => 'Favicon URL',
        'robots' => 'Search engines',

        'form' => 'Embedded form',
        'form_help' => 'A submission subscribes the visitor to the form\'s list and raises the form_submitted automation event — exactly as the hosted form does.',
        'form_none' => 'No form',
        'after_submit' => 'After submit',
        'success_message' => 'Success message',
        'redirect_url' => 'Redirect URL',
        'thank_you_html' => 'Thank-you page content',
        'thank_you_help' => 'HTML shown on /p/{slug}/thank-you. Leave empty to show the success message.',

        'custom_css' => 'Custom CSS',
        'custom_js' => 'Custom JavaScript',
        'custom_code_help' => 'Injected into this page only.',
        'ga_measurement_id' => 'Google Analytics ID',
        'gtm_container_id' => 'Google Tag Manager ID',
        'fb_pixel_id' => 'Facebook Pixel ID',
        'tracking_ids_help' => 'IDs only — never a <script> snippet. The platform emits the vendor\'s own loader, so an ID that does not match the vendor format is ignored rather than injected.',

        'published_at' => 'Published',
        'scheduled_at' => 'Publish at',
        'scheduled_help' => 'The page goes live the moment this time passes. Until then it answers 404.',
        'ab_enabled' => 'Run an A/B test',
        'ab_help' => 'With this off, every visitor sees the page content and variants are ignored.',
        'template' => 'Starter design',

        'access_password' => 'Page password',
        'access_password_help' => 'Leave empty for a public page. When set, visitors must enter the password before any content is rendered.',
        'access_password_set' => 'A password is set. Type a new one to replace it, or leave this empty to keep it.',
        'access_password_remove' => 'Remove the password (make the page public)',
    ],

    'after_submit' => [
        'message' => 'Show a message',
        'thank_you' => 'Go to the thank-you page',
        'redirect' => 'Redirect to a URL',
    ],

    'robots' => [
        'index,follow' => 'Index and follow links',
        'noindex,follow' => 'Do not index, follow links',
        'index,nofollow' => 'Index, do not follow links',
        'noindex,nofollow' => 'Do not index or follow links',
    ],

    'ssl' => [
        'none' => 'Not applicable',
        'pending' => 'Pending',
        'active' => 'Active',
    ],

    'columns' => [
        'visits' => 'Visits',
        'unique_visitors' => 'Unique visitors',
        'conversions' => 'Conversions',
        'conversion_rate' => 'Conv. rate',
        'domain' => 'Domain',
    ],

    'actions' => [
        'create' => 'New landing page',
        'build' => 'Builder',
        'settings' => 'Settings',
        'variants' => 'A/B variants',
        'analytics' => 'Analytics',
        'gallery' => 'Template gallery',
        'duplicate' => 'Duplicate',
        'publish' => 'Publish',
        'unpublish' => 'Unpublish',
        'schedule' => 'Schedule',
        'preview' => 'Preview',
        'open' => 'Open page',
        'revisions' => 'Revisions',
        'restore' => 'Restore',
        'close' => 'Close',
        'back_to_index' => 'Back to landing pages',
        'verify_domain' => 'Check DNS',
        'use_design' => 'Use this design',
        'add_variant' => 'Add variant',
        'declare_winner' => 'Declare winner',
        'clear_winner' => 'Clear winner',
        'delete_variant' => 'Delete variant',
    ],

    'notifications' => [
        'duplicated' => 'Duplicating the page — it will appear in the list shortly.',
        'published' => 'Landing page published.',
        'unpublished' => 'Landing page unpublished.',
        'scheduled' => 'Landing page scheduled.',
        'revision_restored' => 'Revision restored.',
        'design_applied' => 'Starter design applied.',
        'variant_saved' => 'Variant saved.',
        'variant_deleted' => 'Variant deleted.',
        'winner_declared' => 'Winner declared — it now receives all traffic.',
        'winner_cleared' => 'Winner cleared — traffic is split again.',
        'domain_verified' => 'Domain verified.',
        'domain_failed' => 'The DNS record does not point at :target yet.',
    ],

    'errors' => [
        'no_domain' => 'Assign a custom domain first.',
        'schedule_in_past' => 'Choose a time in the future.',
        'ga_id' => 'That does not look like a Google Analytics measurement ID (for example G-XXXXXXXXXX).',
        'gtm_id' => 'That does not look like a Google Tag Manager container ID (for example GTM-XXXXXXX).',
        'pixel_id' => 'A Facebook Pixel ID is a number.',
        'slug' => 'Use letters, numbers and dashes only.',
    ],

    'builder_page' => [
        'title' => 'Builder — :name',
        'saved' => 'Saved',
        'saving' => 'Saving…',
        'save_failed' => 'Could not save',
        'merge_tags' => 'Insert tag',
        'editing_variant' => 'Editing variant: :name',
        'editing_page' => 'Editing the page content',
        'device_hint' => 'Desktop / tablet / mobile switch the canvas width — the page itself is responsive (F-2.6-013).',
    ],

    'revisions' => [
        'heading' => 'Revision history',
        'current_note' => 'The current content is saved as a revision before a restore, so restoring is undoable.',
        'saved_at' => 'Saved :time',
        'restore' => 'Restore',
        'empty' => 'No revisions yet — they are recorded every time the builder saves.',
    ],

    'gallery' => [
        'heading' => 'Start from a design',
        'subheading' => 'Pick a starting point. It is copied onto your page — the gallery never edits it again.',
        'designs' => [
            'blank' => [
                'name' => 'Blank',
                'description' => 'A hero and a form. Everything else is up to you.',
            ],
            'lead-magnet' => [
                'name' => 'Lead magnet',
                'description' => 'Promise, benefits, capture form and social proof — the classic download page.',
            ],
            'webinar' => [
                'name' => 'Webinar registration',
                'description' => 'Hero with a countdown to the session, registration form and an FAQ.',
            ],
            'product-launch' => [
                'name' => 'Product launch',
                'description' => 'Hero, video, feature columns and a sign-up form.',
            ],
            'waitlist' => [
                'name' => 'Waitlist',
                'description' => 'Short and direct: promise, form, answers to the obvious questions.',
            ],
            'pricing' => [
                'name' => 'Pricing',
                'description' => 'Plan comparison with testimonials and a capture form at the end.',
            ],
        ],
    ],

    'block_categories' => [
        'layout' => 'Layout',
        'content' => 'Content',
        'conversion' => 'Conversion',
    ],

    'blocks' => [
        'section' => 'Section',
        'hero' => 'Hero',
        'columns' => 'Columns',
        'spacer' => 'Spacer',
        'divider' => 'Divider',
        'heading' => 'Heading',
        'text' => 'Text',
        'image' => 'Image',
        'video' => 'Video',
        'faq' => 'FAQ item',
        'html' => 'HTML',
        'form' => 'Form',
        'button' => 'Button',
        'countdown' => 'Countdown',
        'testimonials' => 'Testimonials',
        'pricing' => 'Pricing table',
    ],

    'blocks_content' => [
        'section_heading' => 'A short, specific heading',
        'section_body' => 'One or two sentences that say what the visitor gets and why it matters.',
        'hero_heading' => 'The promise, in one line',
        'hero_body' => 'Say who it is for and what changes for them.',
        'hero_cta' => 'Get started',
        'column_heading' => 'Benefit',
        'column_body' => 'What this does for the visitor.',
        'video_title' => 'Video',
        'faq_question' => 'A question people actually ask',
        'faq_answer' => 'A direct answer, without hedging.',
        'countdown_label' => 'Closes in',
        'testimonial_quote' => 'A specific sentence from a real customer beats five adjectives.',
        'testimonial_by' => 'Name, role',
        'pricing_starter' => 'Starter',
        'pricing_pro' => 'Pro',
        'pricing_feature' => 'What is included',
        'pricing_cta' => 'Choose plan',
    ],

    'variants' => [
        'heading' => 'A/B variants',
        'intro' => 'Each visitor is assigned one variant and keeps it — the assignment is stored in a first-party cookie, so a visitor who converts on a later visit is credited to the variant that persuaded them.',
        'name' => 'Variant',
        'weight' => 'Traffic weight',
        'weight_help' => 'A share, not a percentage: an arm receives weight ÷ total of the enabled arms.',
        'share' => 'Traffic share',
        'control' => 'Control',
        'enabled' => 'Enabled',
        'winner' => 'Winner',
        'uplift' => 'Uplift vs control',
        'confidence' => 'Confidence',
        'not_enough_data' => 'Not enough data',
        'significant' => 'Significant',
        'not_significant' => 'Not significant',
        'suggested_winner' => 'The data supports :name as the winner.',
        'no_suggested_winner' => 'No variant is a statistically significant winner yet.',
        'winner_note' => 'A declared winner receives 100% of traffic; the split stops.',
        'empty' => 'No variants yet. Add one to start an A/B test.',
        'method' => 'Confidence is a two-tailed two-proportion z-test on the conversion rates, and needs at least :min visits per arm before it reports anything.',
    ],

    'analytics' => [
        'heading' => 'Analytics',
        'visits' => 'Visits',
        'unique_visitors' => 'Unique visitors',
        'conversions' => 'Conversions',
        'conversion_rate' => 'Conversion rate',
        'by_source' => 'By source',
        'source' => 'Source',
        'empty' => 'No visits recorded yet.',
        'privacy_note' => 'Visitors are counted with a first-party cookie stored as a one-way hash; IP addresses follow the platform anonymisation setting.',
    ],

    'public' => [
        'success_default' => 'Thanks — you are on the list.',
        'thanks_heading' => 'Thank you',

        // F-1.8-006 password gate.
        'password_heading' => 'This page is protected',
        'password_lead' => 'Enter the password to continue.',
        'password_label' => 'Password',
        'password_submit' => 'Unlock',
        'password_wrong' => 'That password is not correct.',
    ],

];
