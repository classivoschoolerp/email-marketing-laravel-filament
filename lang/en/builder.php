<?php

declare(strict_types=1);

/*
 * WP-42 shared drag-and-drop builder features (spec §2.5.4):
 *   F-2.5.4-004  brand kit — saved colours, fonts, logo
 *   F-2.5.4-009  image editor — crop, resize, filters, text overlay
 *   F-2.5.4-010  stock image library — Unsplash / Pexels
 *
 * They live in one file, next to lang/en/blocks.php, for the same reason the
 * saved-blocks strings do: resources/js/template-builder.js is mounted by the
 * template builder AND the campaign content builder, so these strings must not
 * be duplicated per module. They reach the browser through the `builder` key
 * of the JSON config the builder pages render.
 */
return [

    /* ---------------------------------------------------------------- *
     *  F-2.5.4-004 — brand kit
     * ---------------------------------------------------------------- */
    'brand_kit' => [
        'label' => 'Brand kit',
        'category' => 'Brand',
        'colors' => 'Brand colours',
        'edit' => 'Brand kit',
        'save' => 'Save brand kit',
        'apply' => 'Apply brand styles',
        'apply_hint' => 'Sets the message background, text colour and fonts from your brand kit.',
        'applied' => 'Brand styles applied',
        'logo_block' => 'Brand logo',
        'logo_alt' => 'Logo',
        'empty' => 'No brand kit yet — save colours, fonts and a logo to reuse them in every design.',

        'fields' => [
            'colors' => 'brand colours',
            'color_name' => 'colour name',
            'color_value' => 'colour',
            'heading_font' => 'heading font',
            'body_font' => 'body font',
            'logo' => 'logo',
        ],

        'roles' => [
            'heading' => 'Headings',
            'body' => 'Body text',
        ],
    ],

    /* ---------------------------------------------------------------- *
     *  F-2.5.4-010 — stock image library
     * ---------------------------------------------------------------- */
    'stock' => [
        'label' => 'Stock images',
        'search' => 'Search stock images',
        'search_placeholder' => 'Search photos…',
        'provider' => 'Library',
        'empty' => 'No photos matched that search.',
        'unavailable' => 'No stock image library is configured.',
        'failed' => 'The stock image library did not answer.',
        'insert' => 'Insert',
        'credit' => 'Photo by :author on :provider',

        'fields' => [
            'query' => 'search term',
            'provider' => 'library',
            'photo_id' => 'photo',
        ],

        'providers' => [
            'unsplash' => 'Unsplash',
            'pexels' => 'Pexels',
        ],
    ],

    /* ---------------------------------------------------------------- *
     *  F-2.5.4-009 — image editor
     * ---------------------------------------------------------------- */
    'image_editor' => [
        'label' => 'Edit image',
        'hint' => 'Select an image block, then crop, resize, filter or caption it.',
        'nothing_selected' => 'Select an image block first.',
        'crop' => 'Crop',
        'resize' => 'Width',
        'filter' => 'Filter',
        'overlay' => 'Text overlay',
        'overlay_placeholder' => 'Overlay text',
        'apply' => 'Save as new image',
        'cancel' => 'Cancel',
        'saved' => 'Edited image saved',
        'failed' => 'Could not save the edited image.',

        'filters' => [
            'none' => 'None',
            'grayscale' => 'Greyscale',
            'sepia' => 'Sepia',
            'contrast' => 'Contrast',
            'brighten' => 'Brighten',
            'darken' => 'Darken',
        ],
    ],
];
