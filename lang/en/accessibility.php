<?php

declare(strict_types=1);

/*
 * §2.3.5 F-2.3.5-007 — accessibility check (alt text, contrast).
 *
 * Its own file because the checker is content-module infrastructure shared by
 * campaigns (preflight + preview) and templates (preview), and neither module
 * owns it.
 */

return [
    'title' => 'Accessibility',

    'severity' => [
        'error' => 'Error',
        'warning' => 'Warning',
        'notice' => 'Notice',
    ],

    'clean' => 'No problems found by the checks this build can make.',
    'summary' => ':errors errors, :warnings warnings, :notices notices',

    'scope' => 'Checked statically against this HTML: inline styles, bgcolor/font attributes and simple <style> rules. Background images, colours with transparency, external stylesheets and the client\'s own dark-mode inversion cannot be resolved here and are reported as unresolved, never as a pass.',

    'rules' => [
        'missing_lang' => [
            'label' => 'The document declares no language',
            'fix' => 'Add a language to the root element, for example <html lang="en">, so screen readers use the right pronunciation.',
        ],
        'img_missing_alt' => [
            'label' => 'Image has no alt attribute',
            'fix' => 'Add alt="…" describing what the image conveys, or alt="" if it is purely decorative.',
        ],
        'img_empty_alt' => [
            'label' => 'Image is marked decorative with alt=""',
            'fix' => 'An empty alt hides the image from screen readers. Confirm the image carries no information; otherwise describe it.',
        ],
        'img_decorative_alt' => [
            'label' => 'Spacer image carries alt text',
            'fix' => 'Spacers and tracking pixels should use alt="" so they are skipped instead of announced.',
        ],
        'link_no_text' => [
            'label' => 'Link has no readable text',
            'fix' => 'Give the link visible text, an image with alt text, or an aria-label — otherwise the raw URL is read out.',
        ],
        'link_generic_text' => [
            'label' => 'Link text says nothing on its own',
            'fix' => 'Replace wording like "click here" with the destination, for example "Read the May report".',
        ],
        'heading_order' => [
            'label' => 'Heading levels skip a step',
            'fix' => 'Start at <h1> and go down one level at a time; use styling, not a bigger heading tag, to change size.',
        ],
        'layout_table' => [
            'label' => 'Layout table is announced as a data table',
            'fix' => 'Add role="presentation" to a table used only for layout, or give a real data table a <caption> and <th> cells.',
        ],
        'small_font' => [
            'label' => 'Text is smaller than the readable minimum',
            'fix' => 'Use at least :minimum_px px for body text; many people cannot enlarge text in an email client.',
        ],
        'contrast_low' => [
            'label' => 'Text contrast is below the WCAG AA minimum',
            'fix' => 'Darken the text or lighten the background until the ratio reaches the required minimum.',
        ],
        'contrast_unresolved' => [
            'label' => 'Text contrast could not be determined',
            'fix' => 'The colours here come from something this check cannot read statically. Verify this text by eye or in a real client.',
        ],
        'css_not_parsed' => [
            'label' => 'Some <style> rules were not analysed',
            'fix' => 'Only simple element, .class and #id selectors are read. Contrast for elements styled by the remaining rules may be wrong in either direction.',
        ],
    ],

    'context' => [
        'ratio' => 'Ratio :ratio:1, needs :required:1',
        'colors' => 'Text :foreground on :background',
        'size' => ':size_px px',
        'heading' => 'h:from → h:to',
        'selectors' => ':selectors rule(s)',
        'reason' => [
            'transparent' => 'the colour is transparent',
            'gradient' => 'the background is a gradient',
            'background_image' => 'the background is an image',
            'css_variable' => 'the colour comes from a CSS variable',
            'current_color' => 'the colour is currentColor',
            'alpha' => 'the colour is partly transparent',
            'unknown_colour' => 'the colour name is not recognised',
            'unresolved' => 'the colour is not declared here',
        ],
    ],
];
