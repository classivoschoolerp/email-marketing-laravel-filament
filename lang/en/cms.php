<?php

declare(strict_types=1);

/*
| Public marketing site + CMS (spec §1.1, §1.11, §3.24 — WP-38).
| Covers: articles, article categories, static pages, and the public-facing
| strings of the marketing homepage / blog.
*/

return [

    'nav_group' => 'Content & Marketing',

    'articles' => [
        'label' => 'Article',
        'label_plural' => 'Articles',
        'navigation_label' => 'Articles',
        'sections' => [
            'content' => 'Content',
            'publication' => 'Publication',
            'seo' => 'SEO',
            'media' => 'Featured image',
            'brand' => 'Brand',
        ],
        'fields' => [
            'title' => 'Title',
            'slug' => 'Slug',
            'slug_help' => 'Leave blank to generate one from the title. Must be unique per brand.',
            'excerpt' => 'Excerpt',
            'content' => 'Body',
            'category' => 'Category',
            'author' => 'Author',
            'status' => 'Status',
            'published_at' => 'Publish at',
            'published_at_help' => 'A future date keeps the article invisible until then.',
            'featured' => 'Featured',
            'featured_image' => 'Featured image',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description',
            'meta_keywords' => 'Meta keywords',
            'canonical_url' => 'Canonical URL',
            'no_index' => 'Exclude from search engines',
            'domain' => 'Brand / domain',
            'domain_help' => 'Leave blank to show on every brand. A brand-specific article is served only on that brand’s host.',
            'views' => 'Views',
            'created_at' => 'Created',
        ],
        'filters' => [
            'status' => 'Status',
            'category' => 'Category',
            'domain' => 'Brand',
        ],
        'actions' => [
            'create' => 'New article',
            'view_public' => 'View on site',
        ],
    ],

    'categories' => [
        'label' => 'Article category',
        'label_plural' => 'Article categories',
        'navigation_label' => 'Article categories',
        'fields' => [
            'name' => 'Name',
            'slug' => 'Slug',
            'description' => 'Description',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description',
            'sort_order' => 'Sort order',
            'active' => 'Active',
            'domain' => 'Brand / domain',
            'articles_count' => 'Articles',
        ],
        'actions' => [
            'create' => 'New category',
        ],
    ],

    'pages' => [
        'label' => 'Page',
        'label_plural' => 'Pages',
        'navigation_label' => 'Pages',
        'sections' => [
            'content' => 'Content',
            'publication' => 'Publication',
            'seo' => 'SEO',
            'navigation' => 'Navigation',
        ],
        'fields' => [
            'title' => 'Title',
            'slug' => 'Slug',
            'slug_help' => 'The page is served at /pages/{slug}.',
            'content' => 'HTML body',
            'content_help' => 'Full HTML. Rendered as authored — only administrators can edit this.',
            'system_key' => 'System page',
            'system_key_help' => 'Marks the page the application links to. System pages cannot be deleted.',
            'status' => 'Status',
            'show_in_footer' => 'Show in footer',
            'sort_order' => 'Sort order',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description',
            'meta_keywords' => 'Meta keywords',
            'no_index' => 'Exclude from search engines',
            'domain' => 'Brand / domain',
            'domain_help' => 'Leave blank to show on every brand.',
        ],
        'actions' => [
            'create' => 'New page',
            'view_public' => 'View on site',
        ],
        'notifications' => [
            'system_undeletable' => 'System pages (Terms, Privacy, Cookie) cannot be deleted — edit them instead.',
        ],
    ],

    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'system_keys' => [
        'terms' => 'Terms & conditions',
        'privacy' => 'Privacy policy',
        'cookie' => 'Cookie policy',
    ],

    'marketing' => [
        'title' => 'Public site & homepage',
        'navigation_label' => 'Public site',
        'sections' => [
            'switch' => 'Public site',
            'switch_description' => 'Turn the whole marketing site off for a pure self-hosted installation. Subscriber-facing pages (subscribe, unsubscribe, preferences) are never affected.',
            'hero' => 'Hero',
            'features' => 'Features',
            'pricing' => 'Pricing table',
            'pricing_description' => 'Built from the live price plans — nothing is duplicated here.',
            'testimonials' => 'Testimonials',
            'faqs' => 'FAQ',
            'blog' => 'Blog',
            'seo' => 'SEO, sitemap & robots',
        ],
        'fields' => [
            'public_site_enabled' => 'Enable the public site',
            'hero_heading' => 'Hero heading',
            'hero_subheading' => 'Hero sub-heading',
            'hero_cta_label' => 'Call-to-action label',
            'hero_cta_url' => 'Call-to-action URL',
            'hero_image_path' => 'Hero image',
            'features_heading' => 'Features heading',
            'features' => 'Features',
            'feature_title' => 'Title',
            'feature_description' => 'Description',
            'feature_icon' => 'Icon',
            'pricing_enabled' => 'Show the pricing table',
            'pricing_heading' => 'Pricing heading',
            'pricing_currency' => 'Display currency',
            'pricing_currency_help' => 'Plans not sold in this currency are listed without a price — no conversion is invented.',
            'testimonials_heading' => 'Testimonials heading',
            'testimonials' => 'Testimonials',
            'testimonial_name' => 'Name',
            'testimonial_role' => 'Role / company',
            'testimonial_quote' => 'Quote',
            'faqs_heading' => 'FAQ heading',
            'faqs' => 'Questions',
            'faq_question' => 'Question',
            'faq_answer' => 'Answer',
            'blog_enabled' => 'Enable the blog',
            'blog_heading' => 'Blog heading',
            'blog_page_size' => 'Articles per page',
            'sitemap_enabled' => 'Serve sitemap.xml',
            'robots_enabled' => 'Serve robots.txt',
            'robots_extra' => 'Extra robots.txt directives',
            'meta_title' => 'Default meta title',
            'meta_description' => 'Default meta description',
        ],
    ],

    'public' => [
        'blog' => 'Blog',
        'login' => 'Sign in',
        'register' => 'Create an account',
        'get_started' => 'Get started',
        'features' => 'Features',
        'pricing' => 'Pricing',
        'testimonials' => 'What our customers say',
        'faq' => 'Frequently asked questions',
        'latest_articles' => 'Latest articles',
        'categories' => 'Categories',
        'no_articles' => 'There is nothing published here yet.',
        'choose_plan' => 'Choose plan',
        'price_on_request' => 'On request',
        'trial_days' => ':days-day free trial',
        'social' => [
            'facebook' => 'Facebook',
            'twitter' => 'X',
            'instagram' => 'Instagram',
            'linkedin' => 'LinkedIn',
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
        ],
    ],

    // §3.24 F-3.24-003 — menu builder for the public site.
    'menus' => [
        'navigation_label' => 'Menus',
        'label' => 'Menu',
        'label_plural' => 'Menus',
        'all_brands' => 'All brands',
        'top_level' => 'Top level',

        'sections' => [
            'menu' => 'Menu',
        ],

        'locations' => [
            'header' => 'Header',
            'footer' => 'Footer',
        ],

        'targets' => [
            '_self' => 'Same tab',
            '_blank' => 'New tab',
        ],

        'types' => [
            'page' => 'Static page',
            'category' => 'Article category',
            'blog' => 'Blog index',
            'home' => 'Home',
            'login' => 'Log in',
            'register' => 'Sign up',
            'url' => 'Custom URL',
        ],

        'fields' => [
            'name' => 'Name',
            'location' => 'Position',
            'location_help' => 'A menu replaces the built-in links in that position. With no menu defined, the default links are shown.',
            'domain' => 'Brand domain',
            'domain_help' => 'Leave empty for a menu every brand inherits. A brand with its own menu for this position replaces the shared one.',
            'active' => 'Active',
            'items' => 'Items',
            'label' => 'Label',
            'parent' => 'Parent item',
            'parent_help' => 'Choose a top-level item to nest this one under. Menus are one level deep.',
            'type' => 'Links to',
            'page' => 'Page',
            'category' => 'Category',
            'url' => 'URL',
            'url_help' => 'Must start with http:// or https://. Anything else is refused at save and ignored at render.',
            'target' => 'Opens in',
            'visible' => 'Visible',
        ],

        'items' => [
            'title' => 'Items',
            'create' => 'Add item',
            'children' => ':count sub-item|:count sub-items',
        ],

        'actions' => [
            'create' => 'Create menu',
        ],

        'errors' => [
            'unknown_location' => 'Unknown menu position ":location".',
            'duplicate_location' => 'There is already a :location menu for this brand.',
        ],
    ],

    'errors' => [
        'duplicate_slug' => 'The slug ":slug" is already used by another item on this brand.',
        'unknown_system_key' => 'Unknown system page key ":key".',
    ],

];
