<?php

declare(strict_types=1);

// WP-10 — templates module strings (spec §2.5, §3.14).
return [

    'resource' => [
        'label' => 'Email template',
        'plural_label' => 'Email templates',
        'navigation_label' => 'Templates',
        'navigation_group' => 'Content & Marketing',
    ],

    'categories' => [
        'label' => 'Template category',
        'plural_label' => 'Template categories',
        'navigation_label' => 'Template categories',
        'create' => 'New category',
    ],

    'gallery' => [
        'label' => 'Gallery template',
        'plural_label' => 'Template gallery',
        'navigation_label' => 'Template gallery',
        'all_groups' => 'All customers',
    ],

    'builders' => [
        'grapesjs' => 'Drag & drop (GrapesJS)',
        // WP-27B (F-2.5.3-001): the simple built-in block builder.
        'basic' => 'Simple blocks (built-in)',
        'html' => 'Raw HTML',
        'richtext' => 'Rich text',
        'stripo' => 'Stripo',
        'unlayer' => 'Unlayer',
    ],

    // F-2.5.3-001 — basic/simple built-in drag & drop builder (WP-27B).
    'basic_builder' => [
        'title' => 'Simple builder — :name',
        'blocks_heading' => 'Blocks',
        'preview_heading' => 'Preview',
        'empty' => 'No blocks yet — add one above.',
        'empty_image' => 'No image selected',
        'empty_rss' => 'No feed URL set',
        'empty_countdown' => 'No countdown date set',
        'countdown_expired' => 'This offer has ended.',
        'hint' => 'Drag a block to reorder it, or use the arrows. Text blocks accept merge tags and [label](url) links. The HTML is regenerated as tables on every change, so it renders in Outlook too.',
        'types' => [
            'text' => 'Text',
            'image' => 'Image',
            'button' => 'Button',
            'divider' => 'Divider',
            'rss' => 'RSS feed',
            'countdown' => 'Countdown timer',
        ],
        'actions' => [
            'edit' => 'Edit block',
            'remove' => 'Remove block',
            'duplicate' => 'Duplicate block',
            'move_up' => 'Move up',
            'move_down' => 'Move down',
        ],
        'fields' => [
            'content' => 'Text',
            'content_help' => 'Merge tags such as {SUBSCRIBER_FIRST_NAME} work here. Use [label](https://example.com) to add a link.',
            'align' => 'Alignment',
            'font_size' => 'Font size (px)',
            'color' => 'Colour',
            'background' => 'Background colour',
            'src' => 'Image URL',
            'alt' => 'Alt text',
            'href' => 'Link URL',
            'width' => 'Width (px)',
            'label' => 'Button label',
            'thickness' => 'Thickness (px)',
            'url' => 'Feed URL',
            'items' => 'Number of items',
            'show_date' => 'Show dates',
            'show_excerpt' => 'Show excerpts',
            'until' => 'Counts down to (UTC)',
            'countdown_label' => 'Label above the timer',
            'expired_label' => 'Text shown once it has passed',
        ],
        'align' => [
            'left' => 'Left',
            'center' => 'Centre',
            'right' => 'Right',
        ],
        'defaults' => [
            'text_content' => 'Write your message here.',
            'button_label' => 'Click here',
        ],
        'notifications' => [
            'max_blocks' => 'A template can hold at most :max blocks.',
        ],
    ],

    'form' => [
        'details' => 'Details',
        'content' => 'Content',
    ],

    'fields' => [
        'name' => 'Name',
        'category' => 'Category',
        'builder' => 'Builder',
        'default_builder' => 'Default builder',
        'content' => 'HTML content',
        'google_fonts' => 'Google Fonts URLs',
        'merge_tags' => 'Available merge tags',
        'thumbnail' => 'Thumbnail',
        'ownership' => 'Source',
        'is_default' => 'Default',
        'is_featured' => 'Featured',
        'published' => 'Published',
        'gallery_groups' => 'Customer groups',
        'sort_order' => 'Sort order',
        'templates_count' => 'Templates',
        'updated_at' => 'Updated',
        'recipients' => 'Recipients',
        'zip_file' => 'ZIP file',
        'source_url' => 'Page URL',
        // WP-27B (F-2.5.1-002, D-34 resolved).
        'tags' => 'Tags',
    ],

    'filters' => [
        'category' => 'Category',
        'tag' => 'Tag',
        'ownership' => 'Mine / gallery',
        'published' => 'Published',
    ],

    'placeholders' => [
        'tags' => 'newsletter, promo, black-friday…',
    ],

    'ownership' => [
        'mine' => 'Mine',
        'gallery' => 'Gallery',
    ],

    'actions' => [
        'create' => 'New template',
        'edit' => 'Edit',
        'preview' => 'Preview',
        'duplicate' => 'Duplicate',
        'delete' => 'Delete',
        'export_html' => 'Export HTML',
        'export_zip' => 'Export ZIP',
        'send_test' => 'Send test email',
        'set_default' => 'Set as default',
        'use_template' => 'Use this template',
        'bulk_delete' => 'Delete selected',
        'move_to_category' => 'Move to category',
        'import_zip' => 'Upload ZIP',
        'import_url' => 'Import from URL',
        'import_pack' => 'Import template pack',
        'publish' => 'Publish to gallery',
        'unpublish' => 'Unpublish',
        'layout_grid' => 'Grid view',
        'layout_list' => 'List view',
        'builder_preference' => 'Builder preference',
        'revisions' => 'Revisions',
        'template_settings' => 'Template settings',
        'back_to_index' => 'Back to templates',
        'close' => 'Close',
        'more' => 'More',
    ],

    'help' => [
        'builder' => 'How you want to edit this template. The raw HTML editor is always available.',
        'default_builder' => 'Used as the pre-selected builder for new templates.',
        'google_fonts' => 'Optional Google Fonts stylesheet URLs loaded inside the builder canvas.',
        'recipients' => 'Each address receives the test email rendered with sample subscriber data.',
        'thumbnail_upload' => 'Optional. When empty, the first image found in the content is used.',
        'gallery_groups' => 'Leave empty to publish to every customer.',
        'pack_format' => 'ZIP with one folder per template: index.html, images, and an optional template.json ({"name","category","featured","sort_order"}).',
        'tags' => 'Free-text labels used by the tag filter on the index. Tags are lower-cased and de-duplicated.',
    ],

    'notifications' => [
        'copied' => '":name" was copied to your templates.',
        'duplicated' => 'Template duplicated as ":name".',
        'default_set' => '":name" is now your default template.',
        'moved' => ':count template(s) moved.',
        'imported' => 'Template ":name" imported.',
        'test_queued' => 'Test email queued.',
        'preference_saved' => 'Builder preference saved.',
        'revision_restored' => 'Revision restored.',
        'pack_queued' => 'Template pack import queued.',
        // WP-27B: template bulk actions are queued jobs (Part 8
        // F-Phase-3-Differentiat-006 / docs/ARCHITECTURE.md rule 5).
        'bulk_queued' => 'Queued — :count template(s) will be processed in the background.',
    ],

    'warnings' => [
        'missing_unsubscribe' => 'This template does not contain an unsubscribe tag ({UNSUBSCRIBE_URL}).',
    ],

    'errors' => [
        'quota_exceeded' => 'You have reached your template limit.',
        'unsubscribe_required' => 'The template must contain the {UNSUBSCRIBE_URL} or {DIRECT_UNSUBSCRIBE_URL} tag.',
        'font_host' => 'Font URLs must point to one of: :hosts.',
        'zip_unreadable' => 'The uploaded file is not a readable ZIP archive.',
        'zip_no_html' => 'The archive does not contain an HTML file.',
        'zip_unsafe_entry' => 'The archive contains an unsafe file path.',
        'pack_empty' => 'The pack contains no template folders.',
        'url_unreachable' => 'The URL could not be fetched.',
        'url_too_large' => 'The fetched page is too large to import.',
        'url_empty' => 'The fetched page is empty.',
    ],

    // §2.5.3 F-2.5.3-003 embedded Stripo editor (WP-26)
    'stripo' => [
        'title' => 'Stripo — :name',
        'save' => 'Save',
        'unavailable' => 'The Stripo editor is not configured on this installation.',
        'load_failed' => 'The Stripo editor could not be loaded.',
        'back_to_list' => 'Back to templates',
    ],

    'builder_page' => [
        'title' => 'Builder — :name',
        'saved' => 'Saved',
        'saving' => 'Saving…',
        'save_failed' => 'Save failed — your latest changes are not stored.',
        'merge_tags' => 'Merge tags',
        'fonts_placeholder' => 'Loading fonts…',
        // F-2.5.4-006 hide-on-mobile toggle (WP-27B).
        'hide_on_mobile' => 'Hide on mobile',
        'hide_on_mobile_hint' => 'Hides the selected block on screens up to 600px wide. Uses a media query, which Outlook for Windows ignores — never hide the unsubscribe link or postal address this way.',
        'nothing_selected' => 'Select a block first.',
        // F-2.5.4-013 dark mode styles (WP-42).
        'dark' => [
            'background' => 'Dark bg',
            'background_hint' => 'Background colour the selected block uses when the reader’s device is in dark mode.',
            'text' => 'Dark text',
            'text_hint' => 'Text colour the selected block uses when the reader’s device is in dark mode.',
            'clear' => 'Clear dark',
            'preview' => 'Dark preview',
            'preview_hint' => 'Shows your dark-mode styles in the canvas. Apple Mail, iOS Mail and Outlook for macOS/mobile honour them; Outlook for Windows ignores them and renders the light design.',
            'nothing_selected' => 'Select a block first.',
        ],
    ],

    'revisions' => [
        'heading' => 'Revision history',
        'empty' => 'No revisions yet.',
        'restore' => 'Restore',
        'saved_at' => 'Saved :time',
        'current_note' => 'Restoring records the current content first, so a restore can be undone.',
    ],

    'preview' => [
        'empty' => 'This template has no content yet.',
    ],

    'thumbnail' => [
        'alt' => 'Preview of :name',
    ],

    'starter' => [
        'greeting' => 'Hello {SUBSCRIBER_FIRST_NAME},',
        'unsubscribe' => 'Unsubscribe',
    ],

    'test_email' => [
        'subject' => '[Test] :name',
    ],

    'copy_suffix' => '(copy)',

    // WP-46 §2.5.4 F-2.5.4-014 — AMP for Email.
    'amp' => [
        'title' => 'AMP for Email',
        'body' => 'AMP body (amp4email)',
        'help' => 'An optional interactive version of this email. It is sent as an extra MIME part before the HTML one, so clients that do not support AMP (most of them) still receive your normal email. Gmail also requires the sender to be registered for dynamic email before it will render this part.',
        'validation' => 'Validation',
        'empty' => 'No AMP body — the campaign sends exactly as it does now.',
        'valid' => 'No problems found. The AMP part will be attached.',
        'invalid' => 'The AMP body is not valid amp4email: :errors',
        'skeleton' => 'Insert the required boilerplate',
        'scope' => 'Checked statically: required boilerplate, script and stylesheet rules, allowed amp-* components, forbidden tags, https-only resources and the size limits. This is not the full amphtml validator — a clean result here is not an acceptance guarantee from Gmail.',

        'rules' => [
            'missing_doctype' => 'The document must start with <!doctype html>.',
            'missing_html' => 'The document has no <html> element.',
            'missing_amp4email' => 'The <html> element must carry the ⚡4email (or amp4email) attribute.',
            'missing_charset' => '<meta charset="utf-8"> is required in <head>.',
            'missing_runtime' => 'The AMP runtime script is required.',
            'missing_boilerplate' => '<style amp4email-boilerplate>body{visibility:hidden}</style> is required.',
            'missing_body' => 'The document has no <body> element.',
            'inline_script' => 'Inline scripts are not allowed; only the AMP runtime, official component scripts and <script type="application/json"> configuration.',
            'foreign_script' => 'Only scripts served from cdn.ampproject.org are allowed.',
            'external_stylesheet' => 'External stylesheets are not allowed in AMP for Email.',
            'foreign_style' => 'Only <style amp4email-boilerplate> and <style amp-custom> are allowed.',
            'css_import' => '@import is not allowed in amp-custom CSS.',
            'css_important' => '!important is not allowed in amp-custom CSS.',
            'custom_css_too_large' => 'amp-custom CSS exceeds the 75,000-byte limit (bytes).',
            'forbidden_tag' => 'This tag is not allowed in AMP for Email.',
            'forbidden_component' => 'This AMP component is not on the AMP for Email allow-list.',
            'missing_component_script' => 'This component is used but its <script custom-element> declaration is missing.',
            'insecure_url' => 'Resources must be loaded over https.',
            'document_too_large' => 'The document exceeds the 200,000-byte AMP for Email limit (bytes).',
            'gmail_size_warning' => 'Over Gmail\'s recommended 100,000-byte ceiling for the AMP part (bytes).',
        ],
    ],
];
