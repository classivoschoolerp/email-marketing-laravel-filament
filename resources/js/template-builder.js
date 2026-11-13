/**
 * WP-10 — GrapesJS template builder (spec §2.5.4, docs/DECISIONS.md D-11).
 *
 * Mounted on the Filament builder page (filament/customer/templates/builder).
 * Reads its config from the #template-builder-config JSON tag: save/asset
 * endpoints, initial content, merge tags (CampaignSettings::KNOWN_TEMPLATE_TAGS
 * via MergeTags), Google Fonts URLs, debounce interval and i18n strings.
 *
 * Features wired here:
 *  - grapesjs-preset-newsletter: newsletter-safe blocks (text, columns/grid,
 *    button, divider, image, quote, link) + CSS inlining (juice) + newsletter
 *    style manager sectors (global styles: background, width, fonts, colors,
 *    link styles, padding — F-2.5.4-003)
 *  - extra blocks: heading, spacer, social icons, video thumbnail, audio,
 *    raw HTML (F-2.5.4-001, F-2.3.3-025)
 *  - saved/reusable blocks shared between templates and campaigns
 *    (F-2.3.3-026 / F-2.5.4-005): "Save as block" toolbar action + a
 *    "Saved blocks" category populated from routes/modules/blocks.php
 *  - merge-tag dropdown in the text RTE toolbar plus a toolbar-level insert
 *    (F-2.5.4-002)
 *  - responsive device preview desktop/tablet/mobile (F-2.5.4-007)
 *  - undo/redo + debounced autosave to the policy-checked endpoint with
 *    revision history server-side (F-2.5.4-008)
 *  - image uploads to the tenant asset directory (BuilderController@uploadAsset)
 *  - Google Fonts stylesheets injected into the canvas (F-2.5.4-012)
 */
import grapesjs from 'grapesjs';
import newsletterPreset from 'grapesjs-preset-newsletter';
import 'grapesjs/dist/css/grapes.min.css';

const configEl = document.getElementById('template-builder-config');
const mountEl = document.getElementById('template-builder');

if (configEl && mountEl) {
    const config = JSON.parse(configEl.textContent);

    /* ------------------------------------------------------------------ *
     *  LANDING MODE (WP-34, spec §2.6 F-2.6-002).
     *
     *  The landing-page builder is THIS editor, not a second one: the only
     *  differences are the ones that must differ.
     *    - inlineCss OFF. Juice inlining exists because Outlook strips
     *      <style>; a browser does not, and inlining would flatten the
     *      responsive rules a landing page depends on (F-2.6-013).
     *    - the block palette comes from the server (LandingBlocks), so the
     *      labels are translated and the markup is shared with the starter
     *      designs — adding a landing block never touches this file.
     *    - the canvas is styled with the landing stylesheet, so the editor
     *      shows what the visitor will actually see.
     *    - merge tags are hidden: a landing page has no recipient.
     *  No new npm package is involved.
     * ------------------------------------------------------------------ */
    const isLanding = config.mode === 'landing';

    /* ------------------------------------------------------------------ *
     *  Brand kit (F-2.5.4-004) — read BEFORE init, because the saved
     *  palette has to be handed to GrapesJS's colour picker as swatches at
     *  construction time. The kit is tenant-owned and carries no document
     *  foreign key (D-86's shape), so the very same object arrives here from
     *  the template builder page and the campaign content builder page.
     * ------------------------------------------------------------------ */
    const brandKit = (config.brandKit && config.brandKit.data) || {
        colors: [],
        fonts: {},
        font_stacks: {},
        logo_url: null,
    };
    const brandColors = Array.isArray(brandKit.colors) ? brandKit.colors : [];
    const brandPalette = brandColors.map((color) => color.value).filter(Boolean);

    const initOptions = {
        container: mountEl,
        height: '75vh',
        fromElement: false,
        components: config.contentHtml || '',
        storageManager: false,
        plugins: [(ed) => newsletterPreset(ed, { inlineCss: !isLanding })],
        deviceManager: {
            devices: [
                { id: 'desktop', name: 'Desktop', width: '' },
                { id: 'tablet', name: 'Tablet', width: '768px', widthMedia: '992px' },
                { id: 'mobile', name: 'Mobile', width: '375px', widthMedia: '480px' },
            ],
        },
        canvas: {
            // Google Fonts stylesheets load inside the canvas only (F-2.5.4-012).
            styles: Array.isArray(config.googleFonts) ? config.googleFonts : [],
        },
        assetManager: {
            uploadName: 'file',
            // Custom upload → BuilderController@uploadAsset (policy-checked).
            uploadFile(ev) {
                const files = (ev.dataTransfer ? ev.dataTransfer.files : ev.target.files) || [];
                const am = editor.AssetManager;

                Array.from(files).forEach((file) => {
                    const body = new FormData();
                    body.append('file', file);

                    fetch(config.assetUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': config.csrfToken, Accept: 'application/json' },
                        body,
                    })
                        .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                        .then((json) => am.add({ src: json.url }))
                        .catch(() => setStatus('error'));
                });
            },
        },
    };

    // Brand colours become swatches in EVERY colour input the style manager
    // renders — that is what "applied into the builder's global styles and
    // offered as swatches" means in GrapesJS terms.
    if (brandPalette.length) {
        initOptions.colorPicker = { appendTo: 'parent', palette: [brandPalette] };
    }

    const editor = grapesjs.init(initOptions);

    /* ------------------------------------------------------------------ *
     *  Stored project data (undo-safe full state) takes precedence over
     *  the flat HTML snapshot when both exist.
     * ------------------------------------------------------------------ */
    if (config.contentJson) {
        try {
            editor.loadProjectData(JSON.parse(config.contentJson));
        } catch {
            /* fall back to the HTML snapshot already loaded */
        }
    }

    /* ------------------------------------------------------------------ *
     *  Landing canvas stylesheet (F-2.6-002).
     *
     *  Injected into the canvas DOCUMENT rather than through editor.Css, so
     *  it styles the preview without ever becoming part of the project CSS
     *  that gets saved — the public page loads the same sheet itself.
     * ------------------------------------------------------------------ */
    if (isLanding && config.canvasCss) {
        editor.on('load', () => {
            const doc = editor.Canvas.getDocument();

            if (!doc || !doc.head) return;

            const style = doc.createElement('style');
            style.setAttribute('data-lp-base', '1');
            style.textContent = config.canvasCss;
            doc.head.appendChild(style);
            doc.body.classList.add('lp-body');
        });
    }

    /* ------------------------------------------------------------------ *
     *  Extra content blocks (F-2.5.4-001) — the newsletter preset ships
     *  text, 1–3 columns + 3/7, grid/list items, button, divider, image,
     *  quote and link; these add the remaining spec blocks.
     * ------------------------------------------------------------------ */
    const bm = editor.BlockManager;
    const extraCategory = 'Basic';

    /* ------------------------------------------------------------------ *
     *  Landing blocks (F-2.6-002): sections, hero, columns, form, countdown,
     *  testimonials, pricing tables — defined server-side in
     *  App\Services\Landing\LandingBlocks so their labels are translated and
     *  their markup is shared with the starter designs.
     * ------------------------------------------------------------------ */
    if (isLanding) {
        (config.landingBlocks || []).forEach((block) => {
            bm.add(block.id, {
                label: block.label,
                category: block.category,
                content: block.content,
                attributes: { class: block.icon || 'gjs-fonts gjs-f-b1', title: block.label },
            });
        });
    }

    // WP-26: block labels/prompts come from lang/en/blocks.php through the
    // page config (docs/ARCHITECTURE.md — no hardcoded English); the `||` fallbacks
    // keep the builder usable if a page ships without the key.
    const i18nBlocks = (config.i18n && config.i18n.blocks) || {};
    // F-2.5.4-006 hide-on-mobile strings (lang/en/templates.php · campaigns.php).
    const i18nMobile = (config.i18n && config.i18n.mobile) || {};
    // WP-42 shared builder strings (lang/en/builder.php): brand kit,
    // stock image library, image editor.
    const i18nBuilder = (config.i18n && config.i18n.builder) || {};
    const i18nBrand = i18nBuilder.brand_kit || {};
    const i18nStock = i18nBuilder.stock || {};
    const i18nEditor = i18nBuilder.image_editor || {};
    const blocksApi = config.blocks || {};
    const brandApi = config.brandKit || {};
    const stockApi = config.stock || {};
    const imageEditorApi = config.imageEditor || {};
    // WP-45 §2.12 F-2.12-003/004/005 — catalogue browser + ecommerce blocks.
    const ecomApi = config.ecommerce || {};
    const i18nEcomCatalogue = (ecomApi.i18n && ecomApi.i18n.catalogue) || {};
    const i18nEcomBlocks = (ecomApi.i18n && ecomApi.i18n.blocks) || {};
    // WP-46 §2.5.4 F-2.5.4-011 — AI image generation. Strings ride in the
    // payload (lang/en/ai.php) because this is an AI surface, not a builder one.
    const aiImageApi = config.aiImage || {};
    const i18nAiImage = aiImageApi.i18n || {};

    bm.add('heading-block', {
        label: 'Heading',
        category: extraCategory,
        content:
            '<h1 style="margin:0;padding:8px 0;font-family:Arial,Helvetica,sans-serif;font-size:28px;line-height:1.3;color:#111827;">Heading</h1>',
        attributes: { class: 'gjs-fonts gjs-f-h1p' },
    });

    bm.add('spacer-block', {
        label: 'Spacer',
        category: extraCategory,
        content:
            '<table style="width:100%;border-collapse:collapse;"><tr><td style="height:32px;line-height:32px;font-size:0;">&nbsp;</td></tr></table>',
        attributes: { class: 'gjs-fonts gjs-f-divider' },
    });

    bm.add('social-block', {
        label: 'Social icons',
        category: extraCategory,
        content:
            '<table style="margin:0 auto;border-collapse:collapse;"><tr>' +
            '<td style="padding:6px 10px;"><a href="https://facebook.com/" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#2563eb;text-decoration:none;">Facebook</a></td>' +
            '<td style="padding:6px 10px;"><a href="https://x.com/" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#2563eb;text-decoration:none;">X</a></td>' +
            '<td style="padding:6px 10px;"><a href="https://instagram.com/" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#2563eb;text-decoration:none;">Instagram</a></td>' +
            '<td style="padding:6px 10px;"><a href="https://linkedin.com/" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#2563eb;text-decoration:none;">LinkedIn</a></td>' +
            '</tr></table>',
        attributes: { class: 'gjs-fonts gjs-f-link' },
    });

    bm.add('video-thumb-block', {
        label: 'Video thumbnail',
        category: extraCategory,
        content:
            '<a href="https://example.com/video" target="_blank" style="display:block;position:relative;text-decoration:none;">' +
            '<img src="https://via.placeholder.com/600x338?text=%E2%96%B6+Video" alt="Video" style="display:block;width:100%;height:auto;"/>' +
            '</a>',
        attributes: { class: 'gjs-fonts gjs-f-image' },
    });

    /* ------------------------------------------------------------------ *
     *  Audio widget (F-2.3.3-025).
     *
     *  THE EMAIL-SAFE APPROACH, deliberately: no mail client can be relied
     *  on to play audio. Apple Mail / iOS Mail render HTML5 <audio>; Gmail,
     *  Outlook desktop and Outlook.com strip the tag. HTML5 defines the
     *  children of <audio> as the fallback for clients that do not support
     *  it, and sanitising clients that drop the <audio> element keep its
     *  child nodes — so the anchor below survives BOTH cases:
     *
     *      supports audio  → native player
     *      strips audio    → the link/thumbnail is what remains
     *
     *  The link points at the hosted audio file, so the recipient always
     *  has a working way to listen. Never embed audio as the only content
     *  of a message.
     * ------------------------------------------------------------------ */
    bm.add('audio-block', {
        label: i18nBlocks.audio_label || 'Audio',
        category: extraCategory,
        content:
            '<table role="presentation" style="width:100%;border-collapse:collapse;"><tr><td style="padding:8px 0;text-align:center;">' +
            '<audio controls preload="none" src="https://example.com/audio.mp3" style="width:100%;max-width:480px;">' +
            '<a href="https://example.com/audio.mp3" target="_blank" rel="noopener" ' +
            'style="display:inline-block;padding:12px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;' +
            'color:#ffffff;background-color:#2563eb;border-radius:6px;text-decoration:none;">' +
            (i18nBlocks.audio_listen || '▶ Listen to the audio') +
            '</a></audio>' +
            '<div style="margin-top:6px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b7280;">' +
            (i18nBlocks.audio_caption || 'Audio') +
            '</div>' +
            '</td></tr></table>',
        attributes: { class: 'gjs-fonts gjs-f-video' },
    });

    bm.add('raw-html-block', {
        label: 'HTML',
        category: extraCategory,
        content: '<div data-raw-html="1"><!-- Paste or write raw HTML in the code view --></div>',
        attributes: { class: 'gjs-fonts gjs-f-code' },
    });

    /* ------------------------------------------------------------------ *
     *  Ecommerce blocks (WP-45, F-2.12-004 cart contents / F-2.12-005
     *  recommendations).
     *
     *  These are PLACEHOLDERS, not baked content: the server fills them per
     *  recipient at send time (App\Services\Ecommerce\EcommerceBlocks), which
     *  is the entire point — a campaign designed on Monday and sent on Friday
     *  must show Friday's cart and Friday's prices. The canvas therefore shows
     *  a labelled outline rather than fake products, so nobody mistakes a
     *  design-time sample for what will actually be sent.
     *
     *  The category only exists once a store is connected: no store, no
     *  blocks, no confusing empty feature.
     * ------------------------------------------------------------------ */
    // Local escaper: the shared `esc` below is a `const` declared further down
    // the file, so referencing it here would hit the temporal dead zone.
    const escEcom = (value) =>
        String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

    if (ecomApi.enabled) {
        const ecomCategory = i18nEcomBlocks.category || 'Ecommerce';

        const placeholder = (label) =>
            '<div style="padding:16px;border:1px dashed #9ca3af;border-radius:6px;' +
            'font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6b7280;text-align:center;">' +
            escEcom(label) +
            '<br><span style="font-size:11px;">' +
            escEcom(i18nEcomBlocks.dynamic_hint || '') +
            '</span></div>';

        bm.add('ecom-cart-block', {
            label: i18nEcomBlocks.cart_label || 'Cart contents',
            category: ecomCategory,
            content:
                '<div data-emp-ecom="cart" data-limit="5">' +
                placeholder(i18nEcomBlocks.cart_label || 'Cart contents') +
                '</div>',
            attributes: { class: 'gjs-fonts gjs-f-b3' },
        });

        Object.keys(ecomApi.strategies || {}).forEach((strategy) => {
            const label = ecomApi.strategies[strategy];

            bm.add('ecom-rec-' + strategy, {
                label: (i18nEcomBlocks.recommendations_label || 'Recommended') + ' — ' + label,
                category: ecomCategory,
                content:
                    '<div data-emp-ecom="recommendations" data-strategy="' +
                    escEcom(strategy) +
                    '" data-limit="3">' +
                    placeholder(label) +
                    '</div>',
                attributes: { class: 'gjs-fonts gjs-f-b2' },
            });
        });
    }

    /* ------------------------------------------------------------------ *
     *  Brand kit blocks + fonts (F-2.5.4-004).
     *
     *  The logo block only exists once a logo has been uploaded — an empty
     *  placeholder block that always says "no logo" is noise, and a <img>
     *  with no src is a broken image in every inbox.
     * ------------------------------------------------------------------ */
    if (brandKit.logo_url) {
        bm.add('brand-logo-block', {
            label: i18nBrand.logo_block || 'Brand logo',
            category: i18nBrand.category || 'Brand',
            content:
                '<table role="presentation" style="width:100%;border-collapse:collapse;"><tr>' +
                '<td align="center" style="padding:16px 24px;">' +
                '<img src="' +
                brandKit.logo_url +
                '" alt="' +
                (i18nBrand.logo_alt || 'Logo') +
                '" style="display:block;border:0;max-width:100%;height:auto;">' +
                '</td></tr></table>',
            attributes: { class: 'gjs-fonts gjs-f-image' },
        });
    }

    /**
     * Prepend the brand stacks to every font-family selector the style
     * manager offers, so the tenant's typography is one click away in the
     * GLOBAL styles rather than something to retype per block.
     */
    const applyBrandFontOptions = () => {
        const stacks = brandKit.font_stacks || {};
        const values = Object.keys(stacks).map((role) => stacks[role]);

        if (!values.length) return;

        const options = values
            .filter((value, index) => values.indexOf(value) === index)
            .map((value) => ({ id: value, value: value, name: value.split(',')[0].replace(/"/g, '') }));

        try {
            editor.StyleManager.getSectors().forEach((sector) => {
                const properties = typeof sector.getProperties === 'function' ? sector.getProperties() : null;

                if (!properties) return;

                properties.forEach((property) => {
                    const name = typeof property.getName === 'function' ? property.getName() : property.get('property');

                    if (name !== 'font-family') return;

                    const existing = property.get('options') || property.get('list') || [];

                    property.set('options', options.concat(existing));
                });
            });
        } catch {
            /* a style-manager shape change must never break the editor */
        }
    };

    editor.on('load', applyBrandFontOptions);

    /**
     * "Apply brand styles" — an EXPLICIT action, never automatic. Writing the
     * tenant's palette over an open design without being asked would silently
     * destroy work; offering it as a button does not.
     */
    const applyBrandStyles = () => {
        const stacks = brandKit.font_stacks || {};
        const background = brandColors[0] ? brandColors[0].value : null;
        const text = brandColors[1] ? brandColors[1].value : null;

        const declarations = []
            .concat(stacks.body ? ['font-family:' + stacks.body] : [])
            .concat(text ? ['color:' + text] : [])
            .concat(background ? ['background-color:' + background] : []);

        if (!declarations.length) {
            setStatus('error', i18nBrand.empty);

            return;
        }

        try {
            editor.Css.addRules('body{' + declarations.join(';') + ';}');

            if (stacks.heading) {
                editor.Css.addRules('h1,h2,h3{font-family:' + stacks.heading + ';}');
            }

            setStatus('saved', i18nBrand.applied);
        } catch {
            setStatus('error', i18nBrand.empty);
        }
    };

    /* ------------------------------------------------------------------ *
     *  Saved / reusable blocks (F-2.3.3-026, F-2.5.4-005).
     *
     *  Blocks belong to the tenant, not to a template or a campaign, so
     *  anything saved here is draggable from BOTH builders. The list is
     *  fetched once on boot and refreshed after every save/delete.
     * ------------------------------------------------------------------ */
    const savedCategory = i18nBlocks.category || 'Saved blocks';
    let savedBlockIds = [];

    const jsonHeaders = {
        'X-CSRF-TOKEN': config.csrfToken,
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };

    const renderSavedBlocks = (blocks) => {
        savedBlockIds.forEach((id) => bm.remove(id));
        savedBlockIds = [];

        (blocks || []).forEach((block) => {
            const id = 'saved-block-' + block.uid;

            bm.add(id, {
                label: block.name,
                category: savedCategory,
                content: block.html,
                attributes: { class: 'gjs-fonts gjs-f-b1', title: block.name },
            });

            savedBlockIds.push(id);
        });
    };

    const loadSavedBlocks = () => {
        if (!blocksApi.listUrl) return Promise.resolve();

        return fetch(blocksApi.listUrl, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((json) => renderSavedBlocks(json.blocks))
            .catch(() => {
                /* the panel simply stays empty — never break the editor */
            });
    };

    /** HTML of the current selection, or null when nothing is selected. */
    const selectionHtml = () => {
        const selected = editor.getSelected();

        if (!selected) return null;

        try {
            return selected.toHTML();
        } catch {
            const el = selected.getEl();

            return el ? el.outerHTML : null;
        }
    };

    const saveSelectionAsBlock = () => {
        const html = selectionHtml();

        if (!html) {
            setStatus('error', i18nBlocks.nothing_selected);

            return;
        }

        const name = window.prompt(i18nBlocks.prompt_name || 'Name this block');

        if (!name) return;

        fetch(blocksApi.saveUrl, {
            method: 'POST',
            headers: jsonHeaders,
            body: JSON.stringify({ name: name, html: html }),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => {
                setStatus('saved', i18nBlocks.saved);

                return loadSavedBlocks();
            })
            .catch(() => setStatus('error', i18nBlocks.failed));
    };

    /* ------------------------------------------------------------------ *
     *  Hide-on-mobile / mobile-specific styling (F-2.5.4-006).
     *
     *  The toggle puts the `hide-mobile` class on the SELECTED block and the
     *  matching media query into the project stylesheet, so:
     *    - the mobile device preview above already shows the block gone;
     *    - editor.getCss() carries the rule, so the autosaved snapshot has it
     *      even before the server-side MobileVisibility guard runs.
     *
     *  CLIENT-SUPPORT CAVEAT — say it out loud, because email is not the web:
     *  a media query is the ONLY way to do this (the rule cannot be inlined
     *  onto the element the way juice inlines every other style), and media
     *  queries are NOT universally supported.
     *    honoured by  → iOS Mail, Apple Mail, the Gmail apps, Outlook for
     *                   iOS/Android, most webmail;
     *    ignored by   → Outlook 2007–2019 on Windows (Word rendering engine)
     *                   and any client that strips <style> blocks.
     *  Where it is ignored the block simply STAYS VISIBLE. That is the safe
     *  failure mode, and it is why hide-on-mobile must never be used to hide
     *  the unsubscribe link, the postal address or any other content the
     *  message is legally required to carry. `mso-hide:all` is emitted next
     *  to display:none as a best effort for the Word engine; it is a hint,
     *  not a guarantee.
     * ------------------------------------------------------------------ */
    const HIDE_MOBILE_CLASS = 'hide-mobile';
    const HIDE_MOBILE_CSS =
        '@media only screen and (max-width:600px){.' +
        HIDE_MOBILE_CLASS +
        '{display:none!important;max-height:0!important;overflow:hidden!important;mso-hide:all;}}';

    /** Ensure the rule is part of the project CSS exactly once. */
    const ensureHideMobileRule = () => {
        try {
            if (!editor.getCss().includes('.' + HIDE_MOBILE_CLASS)) {
                editor.Css.addRules(HIDE_MOBILE_CSS);
            }
        } catch {
            /* never break the editor over a stylesheet detail */
        }
    };

    const selectionHidesOnMobile = () => {
        const selected = editor.getSelected();

        return !!selected && selected.getClasses().indexOf(HIDE_MOBILE_CLASS) !== -1;
    };

    const toggleHideOnMobile = () => {
        const selected = editor.getSelected();

        if (!selected) {
            setStatus('error', i18nMobile.nothing_selected || i18nBlocks.nothing_selected);

            return;
        }

        if (selectionHidesOnMobile()) {
            selected.removeClass(HIDE_MOBILE_CLASS);
        } else {
            selected.addClass(HIDE_MOBILE_CLASS);
            ensureHideMobileRule();
        }

        syncHideMobileButton();
    };

    /* ------------------------------------------------------------------ *
     *  Dark mode styles (F-2.5.4-013).
     *
     *  Two halves, and both are needed for the feature to mean anything:
     *
     *   1. PER-BLOCK OVERRIDES. The colour pickers put a value-carrying class
     *      on the selected block — `dm-bg-111827` / `dm-fg-f9fafb` — which is
     *      exactly the convention App\Support\Templates\DarkModeStyles reads
     *      server-side. Because the class carries its own colour there is no
     *      shared numbering to keep in sync: the server can regenerate the
     *      stylesheet from the markup alone, on every save, from either
     *      builder, and from imported HTML that never touched this file.
     *
     *   2. PREVIEW. Email clients apply `prefers-color-scheme` from the
     *      READER's OS, which a canvas iframe cannot be forced into. The
     *      toggle therefore injects the same declarations UNCONDITIONALLY
     *      into the canvas document (plus a dark page surround), which is the
     *      identical trick App\Services\Content\DarkModePreview::MODE_AUTHOR
     *      uses for the campaign preview — the author's own dark styles,
     *      actually applied.
     *
     *  The media query itself is also written into the project CSS so the
     *  autosaved snapshot already carries it before the server-side
     *  DarkModeStyles guard runs — same belt-and-braces as hide-on-mobile.
     * ------------------------------------------------------------------ */
    const i18nDark = (config.i18n && config.i18n.dark) || {};
    const DARK_BG_PREFIX = 'dm-bg-';
    const DARK_FG_PREFIX = 'dm-fg-';

    /** '#111827' → '111827'; anything else → null. */
    const darkHex = (value) => {
        const hex = String(value || '').trim().replace(/^#/, '').toLowerCase();

        return /^[0-9a-f]{6}$/.test(hex) ? hex : null;
    };

    /** Every dark class currently present in the document, de-duplicated. */
    const darkClassesInUse = () => {
        const html = editor.getHtml() || '';
        const found = { bg: [], fg: [] };

        [['bg', DARK_BG_PREFIX], ['fg', DARK_FG_PREFIX]].forEach(([slot, prefix]) => {
            const re = new RegExp('\\b' + prefix + '([0-9a-f]{6})\\b', 'gi');
            let match;

            while ((match = re.exec(html)) !== null) {
                const hex = match[1].toLowerCase();

                if (found[slot].indexOf(hex) === -1) found[slot].push(hex);
            }

            found[slot].sort();
        });

        return found;
    };

    /** The declarations, without the media-query wrapper (used by the preview). */
    const darkDeclarations = () => {
        const used = darkClassesInUse();

        return (
            used.bg.map((hex) => '.' + DARK_BG_PREFIX + hex + '{background-color:#' + hex + '!important;}').join('') +
            used.fg.map((hex) => '.' + DARK_FG_PREFIX + hex + '{color:#' + hex + '!important;}').join('')
        );
    };

    /** Keep the project stylesheet's media query in step with the markup. */
    const ensureDarkRules = () => {
        const declarations = darkDeclarations();

        if (!declarations) return;

        try {
            editor.Css.addRules('@media (prefers-color-scheme:dark){' + declarations + '}');
        } catch {
            /* never break the editor over a stylesheet detail */
        }
    };

    /** Put one dark class on the selection, replacing any previous same-slot one. */
    const setDarkOverride = (prefix, value) => {
        const selected = editor.getSelected();

        if (!selected) {
            setStatus('error', i18nDark.nothing_selected || i18nBlocks.nothing_selected);

            return;
        }

        selected
            .getClasses()
            .filter((name) => String(name).indexOf(prefix) === 0)
            .forEach((name) => selected.removeClass(name));

        const hex = darkHex(value);

        if (hex) {
            selected.addClass(prefix + hex);
            ensureDarkRules();
        }

        renderDarkPreview();
        syncDarkInputs();
    };

    /** The colour currently set on the selection for one slot, or ''. */
    const darkOverrideOf = (prefix) => {
        const selected = editor.getSelected();

        if (!selected) return '';

        const hit = selected.getClasses().filter((name) => String(name).indexOf(prefix) === 0)[0];

        return hit ? '#' + String(hit).slice(prefix.length) : '';
    };

    let darkPreviewOn = false;

    /**
     * Paint (or clear) the canvas dark simulation. The style element lives in
     * the canvas DOCUMENT, never in editor.Css, so it can never leak into the
     * saved project CSS.
     */
    const renderDarkPreview = () => {
        let doc;

        try {
            doc = editor.Canvas.getDocument();
        } catch {
            return;
        }

        if (!doc || !doc.head) return;

        const existing = doc.getElementById('tb-dark-preview');

        if (existing) existing.remove();

        if (!darkPreviewOn) return;

        const style = doc.createElement('style');
        style.id = 'tb-dark-preview';
        style.textContent = 'html,body{background-color:#111827!important;}' + darkDeclarations();
        doc.head.appendChild(style);
    };

    /* ------------------------------------------------------------------ *
     *  Shared helpers for the WP-42 media features.
     * ------------------------------------------------------------------ */
    const esc = (value) =>
        String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

    /** Drop an <img> next to the selection, or at the end of the document. */
    const insertImage = (url, alt) => {
        const html =
            '<img src="' +
            esc(url) +
            '" alt="' +
            esc(alt) +
            '" style="display:block;border:0;max-width:100%;height:auto;">';

        const selected = editor.getSelected();
        const parent = selected && selected.parent ? selected.parent() : null;

        if (parent) {
            parent.append(html, { at: selected.index() + 1 });
        } else {
            editor.getWrapper().append(html);
        }
    };

    /* ------------------------------------------------------------------ *
     *  Brand kit editor (F-2.5.4-004).
     *
     *  Lives in the builder rather than in a settings screen because that is
     *  where the colours and fonts are used: adding a swatch two clicks from
     *  the block you are colouring is the point of a brand kit. Saved values
     *  are tenant-owned, so the next document — template OR campaign — opens
     *  with the same palette, fonts and logo.
     * ------------------------------------------------------------------ */
    const openBrandKitEditor = () => {
        if (!brandApi.saveUrl) return;

        const stacks = brandApi.fontStacks || {};
        const wrap = document.createElement('div');
        wrap.style.minWidth = '480px';

        const colorRow = (color) =>
            '<div class="tb-brand-color" style="display:flex;gap:6px;margin-bottom:6px;">' +
            '<input type="color" class="gjs-field tb-brand-value" value="' +
            esc((color && color.value) || '#2563eb') +
            '">' +
            '<input type="text" class="gjs-field tb-brand-name" style="flex:1;" value="' +
            esc((color && color.name) || '') +
            '">' +
            '<button type="button" class="gjs-btn-prim tb-brand-remove">×</button>' +
            '</div>';

        const fontSelect = (role) =>
            '<label style="display:block;margin-bottom:6px;">' +
            esc((i18nBrand.roles || {})[role] || role) +
            '<select class="gjs-field tb-brand-font" data-role="' +
            role +
            '"><option value="">—</option>' +
            Object.keys(stacks)
                .map(
                    (key) =>
                        '<option value="' +
                        esc(key) +
                        '"' +
                        ((brandKit.fonts || {})[role] === key ? ' selected' : '') +
                        '>' +
                        esc(stacks[key]) +
                        '</option>'
                )
                .join('') +
            '</select></label>';

        wrap.innerHTML =
            '<div style="font-size:12px;opacity:.75;margin-bottom:8px;">' +
            esc(i18nBrand.colors || 'Brand colours') +
            '</div><div id="tb-brand-colors">' +
            (brandColors.length ? brandColors.map(colorRow).join('') : colorRow(null)) +
            '</div>' +
            '<button type="button" id="tb-brand-add" class="gjs-btn-prim" style="margin-bottom:12px;">+</button>' +
            fontSelect('heading') +
            fontSelect('body') +
            '<label style="display:block;margin:8px 0;">' +
            esc((i18nBrand.logo_block || 'Brand logo')) +
            '<input type="file" id="tb-brand-logo" accept="image/*" class="gjs-field"></label>' +
            '<div style="display:flex;gap:8px;align-items:center;">' +
            '<button type="button" id="tb-brand-save" class="gjs-btn-prim">' +
            esc(i18nBrand.save || 'Save brand kit') +
            '</button>' +
            '<span id="tb-brand-note" style="font-size:12px;opacity:.75;"></span>' +
            '</div>';

        const note = wrap.querySelector('#tb-brand-note');
        const list = wrap.querySelector('#tb-brand-colors');

        list.addEventListener('click', (ev) => {
            if (ev.target.classList.contains('tb-brand-remove')) {
                ev.target.closest('.tb-brand-color').remove();
            }
        });

        wrap.querySelector('#tb-brand-add').addEventListener('click', () => {
            list.insertAdjacentHTML('beforeend', colorRow(null));
        });

        wrap.querySelector('#tb-brand-logo').addEventListener('change', (ev) => {
            const file = ev.target.files && ev.target.files[0];

            if (!file || !brandApi.logoUrl) return;

            const body = new FormData();
            body.append('file', file);

            note.textContent = '…';

            fetch(brandApi.logoUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': config.csrfToken, Accept: 'application/json' },
                body: body,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((json) => {
                    brandKit.logo_url = json.brand_kit.logo_url;
                    note.textContent = i18nBrand.applied || '';
                })
                .catch(() => {
                    note.textContent = i18nStock.failed || '';
                });
        });

        wrap.querySelector('#tb-brand-save').addEventListener('click', () => {
            const colors = Array.from(list.querySelectorAll('.tb-brand-color')).map((row) => ({
                name: row.querySelector('.tb-brand-name').value,
                value: row.querySelector('.tb-brand-value').value,
            }));

            const fonts = {};
            wrap.querySelectorAll('.tb-brand-font').forEach((select) => {
                if (select.value) fonts[select.dataset.role] = select.value;
            });

            note.textContent = '…';

            fetch(brandApi.saveUrl, {
                method: 'POST',
                headers: jsonHeaders,
                body: JSON.stringify({ colors: colors, fonts: fonts }),
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((json) => {
                    brandKit.colors = json.brand_kit.colors;
                    brandKit.fonts = json.brand_kit.fonts;
                    brandKit.font_stacks = json.brand_kit.font_stacks;
                    setStatus('saved', i18nBrand.applied);
                    editor.Modal.close();
                })
                .catch(() => {
                    note.textContent = i18nStock.failed || '';
                });
        });

        editor.Modal.setTitle(i18nBrand.label || 'Brand kit');
        editor.Modal.setContent(wrap);
        editor.Modal.open();
    };

    /* ------------------------------------------------------------------ *
     *  Stock image library (F-2.5.4-010).
     *
     *  `config.stock.providers` is [] when the operator has configured no API
     *  key, and the toolbar button below is then never rendered — no key, no
     *  picker, no error. The server enforces the same thing: the provider
     *  field is validated against the CONFIGURED libraries, so the endpoint
     *  cannot be reached by hand either.
     *
     *  A picked photo is NOT hot-linked. The import endpoint downloads it into
     *  the tenant's own library, so an email's images never depend on a third
     *  party's rate limits and never leak the recipient's IP to one.
     * ------------------------------------------------------------------ */
    const stockProviders = Array.isArray(stockApi.providers) ? stockApi.providers : [];

    const openStockPicker = () => {
        if (!stockProviders.length) return;

        const wrap = document.createElement('div');
        wrap.style.minWidth = '520px';

        wrap.innerHTML =
            '<div style="display:flex;gap:8px;margin-bottom:10px;">' +
            '<select id="tb-stock-provider" class="gjs-field" style="max-width:160px;">' +
            stockProviders
                .map((p) => '<option value="' + esc(p.key) + '">' + esc(p.label) + '</option>')
                .join('') +
            '</select>' +
            '<input id="tb-stock-q" class="gjs-field" style="flex:1;" placeholder="' +
            esc(i18nStock.search_placeholder || '') +
            '">' +
            '<button type="button" id="tb-stock-go" class="gjs-btn-prim">' +
            esc(i18nStock.search || 'Search') +
            '</button>' +
            '</div>' +
            '<div id="tb-stock-results" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;' +
            'max-height:420px;overflow:auto;"></div>' +
            '<div id="tb-stock-note" style="margin-top:8px;font-size:12px;opacity:.75;"></div>';

        const results = wrap.querySelector('#tb-stock-results');
        const note = wrap.querySelector('#tb-stock-note');

        const runSearch = () => {
            const provider = wrap.querySelector('#tb-stock-provider').value;
            const q = wrap.querySelector('#tb-stock-q').value.trim();

            if (!q) return;

            note.textContent = '';
            results.innerHTML = '';

            const url =
                stockApi.searchUrl +
                (stockApi.searchUrl.indexOf('?') === -1 ? '?' : '&') +
                'provider=' +
                encodeURIComponent(provider) +
                '&q=' +
                encodeURIComponent(q);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((json) => {
                    const photos = json.photos || [];

                    if (!photos.length) {
                        note.textContent = i18nStock.empty || '';

                        return;
                    }

                    photos.forEach((photo) => {
                        const tile = document.createElement('button');
                        tile.type = 'button';
                        tile.style.cssText = 'padding:0;border:0;background:none;cursor:pointer;';
                        tile.title = (i18nStock.credit || 'Photo by :author on :provider')
                            .replace(':author', photo.author || '')
                            .replace(':provider', json.provider || '');
                        tile.innerHTML =
                            '<img src="' +
                            esc(photo.thumb_url) +
                            '" alt="' +
                            esc(photo.alt) +
                            '" style="width:100%;height:90px;object-fit:cover;border-radius:4px;">';

                        tile.addEventListener('click', () => {
                            note.textContent = '…';

                            fetch(stockApi.importUrl, {
                                method: 'POST',
                                headers: jsonHeaders,
                                body: JSON.stringify({ provider: json.provider, url: photo.full_url }),
                            })
                                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                                .then((stored) => {
                                    insertImage(stored.url, photo.alt || '');
                                    editor.Modal.close();
                                })
                                .catch(() => {
                                    note.textContent = i18nStock.failed || '';
                                });
                        });

                        results.appendChild(tile);
                    });
                })
                .catch(() => {
                    note.textContent = i18nStock.failed || '';
                });
        };

        wrap.querySelector('#tb-stock-go').addEventListener('click', runSearch);
        wrap.querySelector('#tb-stock-q').addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') runSearch();
        });

        editor.Modal.setTitle(i18nStock.label || 'Stock images');
        editor.Modal.setContent(wrap);
        editor.Modal.open();
    };

    /* ------------------------------------------------------------------ *
     *  Product catalogue browser (WP-45, F-2.12-003).
     *
     *  The stock-image picker's exact shape, applied to the tenant's SYNCED
     *  catalogue: a small authenticated JSON endpoint the modal searches, and
     *  a ready-made email-safe card the server rendered. The builder never
     *  talks to a shop API, so a slow or down store can never hang the editor.
     *
     *  A picked product is inserted as ORDINARY HTML — a static card, frozen
     *  at the price it had when it was dragged in. The dynamic alternative is
     *  the recommendation block, and the difference is deliberate: a product
     *  you chose should not silently change under you, while a recommendation
     *  is by definition resolved per recipient.
     * ------------------------------------------------------------------ */
    const openProductPicker = () => {
        if (!ecomApi.enabled || !ecomApi.productsUrl) return;

        const wrap = document.createElement('div');
        wrap.style.minWidth = '560px';

        wrap.innerHTML =
            '<div style="display:flex;gap:8px;margin-bottom:10px;">' +
            '<select id="tb-ecom-store" class="gjs-field" style="max-width:180px;"></select>' +
            '<input id="tb-ecom-q" class="gjs-field" style="flex:1;" placeholder="' +
            escEcom(i18nEcomCatalogue.search_placeholder || '') +
            '">' +
            '<button type="button" id="tb-ecom-go" class="gjs-btn-prim">' +
            escEcom(i18nEcomCatalogue.search || 'Search') +
            '</button>' +
            '</div>' +
            '<div id="tb-ecom-results" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;' +
            'max-height:420px;overflow:auto;"></div>' +
            '<div id="tb-ecom-note" style="margin-top:8px;font-size:12px;opacity:.75;"></div>';

        const results = wrap.querySelector('#tb-ecom-results');
        const note = wrap.querySelector('#tb-ecom-note');
        const storeSelect = wrap.querySelector('#tb-ecom-store');

        const render = (json) => {
            const products = json.products || [];

            // The store filter is populated from the answer, so a tenant with
            // one shop never sees a pointless dropdown of one.
            if (storeSelect.options.length === 0) {
                storeSelect.innerHTML =
                    '<option value="">' +
                    escEcom(i18nEcomCatalogue.all_stores || 'All stores') +
                    '</option>' +
                    (json.stores || [])
                        .map((s) => '<option value="' + escEcom(s.uid) + '">' + escEcom(s.name) + '</option>')
                        .join('');
            }

            results.innerHTML = '';

            if (!products.length) {
                note.textContent = i18nEcomCatalogue.empty || '';
                return;
            }

            note.textContent = '';

            products.forEach((product) => {
                const tile = document.createElement('button');
                tile.type = 'button';
                tile.style.cssText =
                    'padding:8px;border:1px solid rgba(128,128,128,.3);border-radius:6px;' +
                    'background:none;cursor:pointer;text-align:left;';
                tile.innerHTML =
                    (product.image_url
                        ? '<img src="' +
                          escEcom(product.image_url) +
                          '" alt="" style="width:100%;height:90px;object-fit:cover;border-radius:4px;">'
                        : '') +
                    '<div style="margin-top:6px;font-size:12px;line-height:1.3;">' +
                    escEcom(product.title) +
                    '</div>';

                tile.addEventListener('click', () => {
                    // The card HTML came from the server already email-safe.
                    const selected = editor.getSelected();

                    if (selected && typeof selected.append === 'function') {
                        selected.append(product.html);
                    } else {
                        editor.addComponents(product.html);
                    }

                    editor.Modal.close();
                });

                results.appendChild(tile);
            });
        };

        const runSearch = () => {
            const q = wrap.querySelector('#tb-ecom-q').value.trim();
            const store = storeSelect.value;

            const url =
                ecomApi.productsUrl +
                (ecomApi.productsUrl.indexOf('?') === -1 ? '?' : '&') +
                'q=' +
                encodeURIComponent(q) +
                '&store=' +
                encodeURIComponent(store);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then(render)
                .catch(() => {
                    note.textContent = i18nEcomCatalogue.failed || '';
                });
        };

        wrap.querySelector('#tb-ecom-go').addEventListener('click', runSearch);
        wrap.querySelector('#tb-ecom-q').addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') runSearch();
        });
        storeSelect.addEventListener('change', runSearch);

        editor.Modal.setTitle(i18nEcomCatalogue.label || 'Products');
        editor.Modal.setContent(wrap);
        editor.Modal.open();

        runSearch();
    };

    /* ------------------------------------------------------------------ *
     *  AI image generation (WP-46, F-2.5.4-011).
     *
     *  The builder-side entry point of the ONE image implementation — the
     *  campaign/template editor's "generate a header image" action is the
     *  other, and both end up in App\Services\Ai\Images\AiImageGenerator.
     *
     *  `aiImageApi.generateUrl` is null when no configured engine can draw,
     *  and the toolbar button is then never rendered — same contract as the
     *  stock picker. The endpoint re-checks, so hand-crafting the POST does
     *  not get you a picture either.
     *
     *  The generated bytes are STORED in the tenant's own library by the
     *  server and what comes back is a normal asset URL, so an AI image is
     *  indistinguishable from an uploaded one from here on. Nothing is
     *  hot-linked from the provider.
     * ------------------------------------------------------------------ */
    const openAiImagePicker = () => {
        if (!aiImageApi.generateUrl) return;

        const sizes = Array.isArray(aiImageApi.sizes) && aiImageApi.sizes.length
            ? aiImageApi.sizes
            : ['1024x1024'];

        const wrap = document.createElement('div');
        wrap.style.minWidth = '520px';

        wrap.innerHTML =
            '<textarea id="tb-ai-img-prompt" class="gjs-field" rows="3" style="width:100%;" placeholder="' +
            esc(i18nAiImage.prompt_placeholder || '') +
            '"></textarea>' +
            '<div style="display:flex;gap:8px;margin-top:8px;">' +
            '<input id="tb-ai-img-alt" class="gjs-field" style="flex:1;" placeholder="' +
            esc(i18nAiImage.alt || 'Alt text') +
            '">' +
            '<select id="tb-ai-img-size" class="gjs-field" style="max-width:150px;">' +
            sizes.map((s) => '<option value="' + esc(s) + '">' + esc(s) + '</option>').join('') +
            '</select>' +
            '<button type="button" id="tb-ai-img-go" class="gjs-btn-prim">' +
            esc(i18nAiImage.generate || 'Generate') +
            '</button>' +
            '</div>' +
            '<div id="tb-ai-img-results" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;' +
            'margin-top:10px;"></div>' +
            '<div id="tb-ai-img-note" style="margin-top:8px;font-size:12px;opacity:.75;">' +
            esc(
                (i18nAiImage.cost || '').replace(':count', String(aiImageApi.cost || 0)) +
                    ' ' +
                    (i18nAiImage.no_text_hint || '')
            ) +
            '</div>';

        const results = wrap.querySelector('#tb-ai-img-results');
        const note = wrap.querySelector('#tb-ai-img-note');
        const button = wrap.querySelector('#tb-ai-img-go');

        const run = () => {
            const prompt = wrap.querySelector('#tb-ai-img-prompt').value.trim();

            if (!prompt) return;

            const alt = wrap.querySelector('#tb-ai-img-alt').value.trim();
            const size = wrap.querySelector('#tb-ai-img-size').value;

            results.innerHTML = '';
            note.textContent = i18nAiImage.generating || '…';
            button.disabled = true;

            fetch(aiImageApi.generateUrl, {
                method: 'POST',
                headers: jsonHeaders,
                body: JSON.stringify({ prompt: prompt, alt: alt, size: size, count: 1 }),
            })
                .then((res) => res.json().then((json) => (res.ok ? json : Promise.reject(json))))
                .then((json) => {
                    note.textContent = '';

                    (json.images || []).forEach((image) => {
                        const tile = document.createElement('button');
                        tile.type = 'button';
                        tile.style.cssText = 'padding:0;border:0;background:none;cursor:pointer;';
                        tile.innerHTML =
                            '<img src="' +
                            esc(image.url) +
                            '" alt="' +
                            esc(image.alt || alt) +
                            '" style="width:100%;border-radius:4px;">';

                        tile.addEventListener('click', () => {
                            insertImage(image.url, image.alt || alt);
                            editor.Modal.close();
                        });

                        results.appendChild(tile);
                    });
                })
                .catch((json) => {
                    // The server answers with a human sentence on every
                    // refusal (no engine, no credits, rejected bytes), so it
                    // is shown as-is rather than replaced with "failed".
                    note.textContent =
                        (json && json.error) ||
                        (i18nAiImage.errors && i18nAiImage.errors.empty) ||
                        'Failed';
                })
                .finally(() => {
                    button.disabled = false;
                });
        };

        button.addEventListener('click', run);

        editor.Modal.setTitle(i18nAiImage.modal || i18nAiImage.label || 'AI image');
        editor.Modal.setContent(wrap);
        editor.Modal.open();
    };

    /* ------------------------------------------------------------------ *
     *  Image editor — crop, resize, filters, text overlay (F-2.5.4-009).
     *
     *  ENTIRELY CLIENT-SIDE, on a <canvas>, with no new npm package: the
     *  browser already ships everything needed to decode, transform and
     *  re-encode a raster image. The server's only job is to store the result
     *  as a NEW asset in the tenant library — the original is never
     *  overwritten, so an edit is always undoable by pointing the block back
     *  at the old URL.
     *
     *  Filters are computed on ImageData rather than via ctx.filter, which is
     *  still missing in older Safari; pixel maths works in every browser that
     *  can run the editor at all.
     * ------------------------------------------------------------------ */
    const selectedImage = () => {
        const selected = editor.getSelected();

        if (!selected) return null;

        const el = typeof selected.getEl === 'function' ? selected.getEl() : null;
        const src = (selected.getAttributes && selected.getAttributes().src) || selected.get('src') || '';

        if (!src || !el || String(el.tagName).toLowerCase() !== 'img') return null;

        return { component: selected, src: src };
    };

    const applyImageFilter = (ctx, width, height, filter) => {
        if (!filter || filter === 'none') return;

        let data;

        try {
            data = ctx.getImageData(0, 0, width, height);
        } catch {
            return; // tainted canvas — leave the pixels alone
        }

        const px = data.data;

        for (let i = 0; i < px.length; i += 4) {
            const r = px[i];
            const g = px[i + 1];
            const b = px[i + 2];

            if (filter === 'grayscale') {
                const v = 0.299 * r + 0.587 * g + 0.114 * b;
                px[i] = px[i + 1] = px[i + 2] = v;
            } else if (filter === 'sepia') {
                px[i] = Math.min(255, 0.393 * r + 0.769 * g + 0.189 * b);
                px[i + 1] = Math.min(255, 0.349 * r + 0.686 * g + 0.168 * b);
                px[i + 2] = Math.min(255, 0.272 * r + 0.534 * g + 0.131 * b);
            } else if (filter === 'contrast') {
                const f = (259 * (40 + 255)) / (255 * (259 - 40));
                px[i] = Math.max(0, Math.min(255, f * (r - 128) + 128));
                px[i + 1] = Math.max(0, Math.min(255, f * (g - 128) + 128));
                px[i + 2] = Math.max(0, Math.min(255, f * (b - 128) + 128));
            } else if (filter === 'brighten') {
                px[i] = Math.min(255, r * 1.2);
                px[i + 1] = Math.min(255, g * 1.2);
                px[i + 2] = Math.min(255, b * 1.2);
            } else if (filter === 'darken') {
                px[i] = r * 0.8;
                px[i + 1] = g * 0.8;
                px[i + 2] = b * 0.8;
            }
        }

        ctx.putImageData(data, 0, 0);
    };

    const openImageEditor = () => {
        const target = selectedImage();

        if (!target) {
            setStatus('error', i18nEditor.nothing_selected);

            return;
        }

        const filters = i18nEditor.filters || {};
        const wrap = document.createElement('div');
        wrap.style.minWidth = '520px';

        wrap.innerHTML =
            '<canvas id="tb-ie-canvas" style="max-width:100%;border:1px solid rgba(255,255,255,.15);"></canvas>' +
            '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:10px;">' +
            '<label>' + esc(i18nEditor.crop || 'Crop') + ' ↔<input type="range" id="tb-ie-cw" min="10" max="100" value="100" class="gjs-field"></label>' +
            '<label>' + esc(i18nEditor.crop || 'Crop') + ' ↕<input type="range" id="tb-ie-ch" min="10" max="100" value="100" class="gjs-field"></label>' +
            '<label>' + esc(i18nEditor.resize || 'Width') + '<input type="number" id="tb-ie-w" min="40" max="2000" class="gjs-field"></label>' +
            '<label>' + esc(i18nEditor.filter || 'Filter') + '<select id="tb-ie-filter" class="gjs-field">' +
            ['none', 'grayscale', 'sepia', 'contrast', 'brighten', 'darken']
                .map((key) => '<option value="' + key + '">' + esc(filters[key] || key) + '</option>')
                .join('') +
            '</select></label>' +
            '<label style="grid-column:span 2;">' + esc(i18nEditor.overlay || 'Text overlay') +
            '<input type="text" id="tb-ie-text" class="gjs-field" placeholder="' + esc(i18nEditor.overlay_placeholder || '') + '"></label>' +
            '<label>#<input type="color" id="tb-ie-color" value="#ffffff" class="gjs-field"></label>' +
            '<label><select id="tb-ie-pos" class="gjs-field">' +
            '<option value="bottom">▼</option><option value="middle">■</option><option value="top">▲</option>' +
            '</select></label>' +
            '</div>' +
            '<div style="margin-top:10px;display:flex;gap:8px;align-items:center;">' +
            '<button type="button" id="tb-ie-save" class="gjs-btn-prim">' + esc(i18nEditor.apply || 'Save') + '</button>' +
            '<span id="tb-ie-note" style="font-size:12px;opacity:.75;"></span>' +
            '</div>';

        const canvas = wrap.querySelector('#tb-ie-canvas');
        const note = wrap.querySelector('#tb-ie-note');
        const ctx = canvas.getContext('2d');
        const image = new Image();

        image.crossOrigin = 'anonymous';

        const redraw = () => {
            if (!image.width) return;

            const cropW = Math.max(1, Math.round((image.width * Number(wrap.querySelector('#tb-ie-cw').value)) / 100));
            const cropH = Math.max(1, Math.round((image.height * Number(wrap.querySelector('#tb-ie-ch').value)) / 100));
            const sx = Math.round((image.width - cropW) / 2);
            const sy = Math.round((image.height - cropH) / 2);

            const outW = Math.max(40, Number(wrap.querySelector('#tb-ie-w').value) || cropW);
            const outH = Math.max(1, Math.round((cropH / cropW) * outW));

            canvas.width = outW;
            canvas.height = outH;

            ctx.clearRect(0, 0, outW, outH);
            ctx.drawImage(image, sx, sy, cropW, cropH, 0, 0, outW, outH);

            applyImageFilter(ctx, outW, outH, wrap.querySelector('#tb-ie-filter').value);

            const text = wrap.querySelector('#tb-ie-text').value;

            if (text) {
                const size = Math.max(14, Math.round(outW / 14));
                const position = wrap.querySelector('#tb-ie-pos').value;
                const y = position === 'top' ? size * 1.4 : position === 'middle' ? outH / 2 : outH - size * 0.7;

                ctx.font = 'bold ' + size + 'px Arial, Helvetica, sans-serif';
                ctx.textAlign = 'center';
                ctx.lineWidth = Math.max(2, size / 8);
                ctx.strokeStyle = 'rgba(0,0,0,.55)';
                ctx.strokeText(text, outW / 2, y);
                ctx.fillStyle = wrap.querySelector('#tb-ie-color').value;
                ctx.fillText(text, outW / 2, y);
            }
        };

        image.onload = () => {
            wrap.querySelector('#tb-ie-w').value = image.width;
            redraw();
        };
        image.onerror = () => {
            note.textContent = i18nEditor.failed || '';
        };
        image.src = target.src;

        ['#tb-ie-cw', '#tb-ie-ch', '#tb-ie-w', '#tb-ie-filter', '#tb-ie-text', '#tb-ie-color', '#tb-ie-pos'].forEach(
            (selector) => {
                wrap.querySelector(selector).addEventListener('input', redraw);
                wrap.querySelector(selector).addEventListener('change', redraw);
            }
        );

        wrap.querySelector('#tb-ie-save').addEventListener('click', () => {
            note.textContent = '…';

            const finish = (blob) => {
                if (!blob) {
                    note.textContent = i18nEditor.failed || '';

                    return;
                }

                const body = new FormData();
                body.append('file', blob, 'edited.png');
                body.append('name', 'edited');

                fetch(imageEditorApi.saveUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.csrfToken, Accept: 'application/json' },
                    body: body,
                })
                    .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                    .then((stored) => {
                        // A NEW asset: the original file is left untouched.
                        target.component.addAttributes({ src: stored.url });
                        target.component.set('src', stored.url);
                        setStatus('saved', i18nEditor.saved);
                        editor.Modal.close();
                    })
                    .catch(() => {
                        note.textContent = i18nEditor.failed || '';
                    });
            };

            try {
                canvas.toBlob(finish, 'image/png');
            } catch {
                note.textContent = i18nEditor.failed || '';
            }
        });

        editor.Modal.setTitle(i18nEditor.label || 'Edit image');
        editor.Modal.setContent(wrap);
        editor.Modal.open();
    };

    /* ------------------------------------------------------------------ *
     *  Merge tags (F-2.5.4-002): dropdown in the text RTE toolbar.
     * ------------------------------------------------------------------ */
    const tagOptions = ['<option value="">' + config.i18n.merge_tags + '</option>']
        .concat((config.mergeTags || []).map((t) => '<option value="' + t + '">{' + t + '}</option>'))
        .join('');

    // Landing pages have no recipient, so there is nothing to merge.
    if (!isLanding) editor.RichTextEditor.add('merge-tags', {
        icon: '<select class="gjs-field" style="max-width:140px;">' + tagOptions + '</select>',
        event: 'change',
        result: (rte, action) => {
            const select = action.btn.firstChild;
            if (select.value) {
                rte.insertHTML('{' + select.value + '}');
                select.value = '';
            }
        },
        update: (rte, action) => {
            action.btn.firstChild.value = '';
        },
    });

    /* ------------------------------------------------------------------ *
     *  Toolbar: save status, merge-tag insert, undo/redo, device preview.
     * ------------------------------------------------------------------ */
    const toolbar = document.getElementById('template-builder-toolbar');
    const btnCls =
        'tb-btn fi-btn px-2 py-1 text-sm rounded-md ring-1 ring-gray-300 dark:ring-gray-600 text-gray-700 dark:text-gray-200';

    toolbar.innerHTML =
        '<span id="tb-status" class="text-xs text-gray-500 dark:text-gray-400 min-w-16"></span>' +
        '<span class="flex-1"></span>' +
        (isLanding
            ? ''
            : '<select id="tb-merge-tags" class="text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">' +
              tagOptions +
              '</select>') +
        '<button type="button" id="tb-undo" class="' + btnCls + '">↶</button>' +
        '<button type="button" id="tb-redo" class="' + btnCls + '">↷</button>' +
        // F-2.3.3-026 / F-2.5.4-005 save-selection-as-block.
        (blocksApi.saveUrl
            ? '<button type="button" id="tb-save-block" class="' +
              btnCls +
              '">' +
              (i18nBlocks.save_selection || 'Save as block') +
              '</button>'
            : '') +
        // F-2.5.4-006 per-block hide-on-mobile toggle.
        '<button type="button" id="tb-hide-mobile" class="' +
        btnCls +
        '" title="' +
        (i18nMobile.hide_on_mobile_hint || '') +
        '">' +
        (i18nMobile.hide_on_mobile || 'Hide on mobile') +
        '</button>' +
        // F-2.5.4-013 per-block dark overrides + canvas dark preview.
        (isLanding
            ? ''
            : '<span class="mx-1 h-5 w-px bg-gray-300 dark:bg-gray-600"></span>' +
              '<label class="text-xs text-gray-500 dark:text-gray-400" for="tb-dark-bg">' +
              (i18nDark.background || 'Dark bg') +
              '</label>' +
              '<input type="color" id="tb-dark-bg" class="h-7 w-8 rounded border border-gray-300 dark:border-gray-600" value="#111827" title="' +
              (i18nDark.background_hint || '') +
              '">' +
              '<label class="text-xs text-gray-500 dark:text-gray-400" for="tb-dark-fg">' +
              (i18nDark.text || 'Dark text') +
              '</label>' +
              '<input type="color" id="tb-dark-fg" class="h-7 w-8 rounded border border-gray-300 dark:border-gray-600" value="#f9fafb" title="' +
              (i18nDark.text_hint || '') +
              '">' +
              '<button type="button" id="tb-dark-clear" class="' +
              btnCls +
              '">' +
              (i18nDark.clear || 'Clear dark') +
              '</button>' +
              '<button type="button" id="tb-dark-preview" class="' +
              btnCls +
              '" title="' +
              (i18nDark.preview_hint || '') +
              '">' +
              (i18nDark.preview || 'Dark preview') +
              '</button>') +
        // F-2.5.4-004 brand kit, F-2.5.4-010 stock library, F-2.5.4-009 image editor.
        (brandApi.saveUrl
            ? '<button type="button" id="tb-brand-edit" class="' +
              btnCls +
              '">' +
              (i18nBrand.edit || 'Brand kit') +
              '</button>'
            : '') +
        (brandColors.length || Object.keys(brandKit.font_stacks || {}).length
            ? '<button type="button" id="tb-brand-apply" class="' +
              btnCls +
              '" title="' +
              (i18nBrand.apply_hint || '') +
              '">' +
              (i18nBrand.apply || 'Apply brand styles') +
              '</button>'
            : '') +
        // No configured library = no picker at all, which is the whole
        // "unconfigured means unavailable" contract.
        (stockApi.searchUrl && (stockApi.providers || []).length
            ? '<button type="button" id="tb-stock" class="' +
              btnCls +
              '">' +
              (i18nStock.label || 'Stock images') +
              '</button>'
            : '') +
        // WP-45 F-2.12-003: no connected store = no catalogue button at all.
        (ecomApi.enabled
            ? '<button type="button" id="tb-ecom-products" class="' +
              btnCls +
              '">' +
              (i18nEcomCatalogue.label || 'Products') +
              '</button>'
            : '') +
        (imageEditorApi.saveUrl
            ? '<button type="button" id="tb-image-editor" class="' +
              btnCls +
              '" title="' +
              (i18nEditor.hint || '') +
              '">' +
              (i18nEditor.label || 'Edit image') +
              '</button>'
            : '') +
        // F-2.5.4-011 AI image generation. No engine that can draw = no button.
        (aiImageApi.generateUrl
            ? '<button type="button" id="tb-ai-image" class="' +
              btnCls +
              '" title="' +
              (i18nAiImage.stored_note || '') +
              '">' +
              (i18nAiImage.builder_action || 'AI image') +
              '</button>'
            : '') +
        '<span class="mx-1 h-5 w-px bg-gray-300 dark:bg-gray-600"></span>' +
        '<button type="button" data-device="desktop" class="' + btnCls + ' tb-device">Desktop</button>' +
        '<button type="button" data-device="tablet" class="' + btnCls + ' tb-device">Tablet</button>' +
        '<button type="button" data-device="mobile" class="' + btnCls + ' tb-device">Mobile</button>';

    const statusEl = document.getElementById('tb-status');

    const setStatus = (state, message) => {
        if (!statusEl) return;
        statusEl.textContent =
            message ||
            (state === 'saving' ? config.i18n.saving : state === 'saved' ? config.i18n.saved : config.i18n.save_failed);
        statusEl.classList.toggle('text-danger-600', state === 'error');
    };

    document.getElementById('tb-undo').addEventListener('click', () => editor.UndoManager.undo());
    document.getElementById('tb-redo').addEventListener('click', () => editor.UndoManager.redo());

    const saveBlockBtn = document.getElementById('tb-save-block');
    if (saveBlockBtn) {
        saveBlockBtn.addEventListener('click', saveSelectionAsBlock);
    }

    /* Hide-on-mobile toggle: reflects the selection's current state so the
     * button reads as a checkbox rather than a fire-and-forget action. */
    const hideMobileBtn = document.getElementById('tb-hide-mobile');

    function syncHideMobileButton() {
        if (!hideMobileBtn) return;

        const on = selectionHidesOnMobile();

        hideMobileBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        hideMobileBtn.classList.toggle('ring-primary-500', on);
        hideMobileBtn.textContent =
            (on ? '☑ ' : '☐ ') + (i18nMobile.hide_on_mobile || 'Hide on mobile');
    }

    if (hideMobileBtn) {
        hideMobileBtn.addEventListener('click', toggleHideOnMobile);
        editor.on('component:selected component:deselected', syncHideMobileButton);
        syncHideMobileButton();
    }

    // Re-opening a template that already uses the class must re-register the
    // rule, otherwise the mobile preview would stop honouring it.
    if ((config.contentHtml || '').indexOf(HIDE_MOBILE_CLASS) !== -1) {
        ensureHideMobileRule();
    }

    /* F-2.5.4-013 dark-mode controls. The colour inputs read back the
     * selection's own override so they behave as state, not as fire-and-forget
     * actions — the same posture as the hide-on-mobile checkbox above. */
    const darkBgInput = document.getElementById('tb-dark-bg');
    const darkFgInput = document.getElementById('tb-dark-fg');
    const darkClearBtn = document.getElementById('tb-dark-clear');
    const darkPreviewBtn = document.getElementById('tb-dark-preview');

    function syncDarkInputs() {
        const bg = darkOverrideOf(DARK_BG_PREFIX);
        const fg = darkOverrideOf(DARK_FG_PREFIX);

        if (darkBgInput && bg) darkBgInput.value = bg;
        if (darkFgInput && fg) darkFgInput.value = fg;

        if (darkClearBtn) {
            darkClearBtn.disabled = !bg && !fg;
        }
    }

    if (darkBgInput) {
        darkBgInput.addEventListener('change', (ev) => setDarkOverride(DARK_BG_PREFIX, ev.target.value));
    }

    if (darkFgInput) {
        darkFgInput.addEventListener('change', (ev) => setDarkOverride(DARK_FG_PREFIX, ev.target.value));
    }

    if (darkClearBtn) {
        darkClearBtn.addEventListener('click', () => {
            setDarkOverride(DARK_BG_PREFIX, '');
            setDarkOverride(DARK_FG_PREFIX, '');
        });
    }

    if (darkPreviewBtn) {
        darkPreviewBtn.addEventListener('click', () => {
            darkPreviewOn = !darkPreviewOn;
            darkPreviewBtn.setAttribute('aria-pressed', darkPreviewOn ? 'true' : 'false');
            darkPreviewBtn.classList.toggle('ring-primary-500', darkPreviewOn);
            darkPreviewBtn.textContent =
                (darkPreviewOn ? '☑ ' : '☐ ') + (i18nDark.preview || 'Dark preview');
            renderDarkPreview();
        });
    }

    if (darkBgInput || darkFgInput) {
        editor.on('component:selected component:deselected', syncDarkInputs);
        editor.on('load', renderDarkPreview);
        syncDarkInputs();
    }

    // Re-opening a document that already carries dark classes must re-register
    // the media query, otherwise the rules would be dropped on the next save.
    if (/\bdm-(bg|fg)-[0-9a-f]{6}\b/i.test(config.contentHtml || '')) {
        ensureDarkRules();
    }

    /* WP-42 toolbar wiring: brand kit (F-2.5.4-004), stock library
     * (F-2.5.4-010), image editor (F-2.5.4-009). */
    const brandEditBtn = document.getElementById('tb-brand-edit');
    const brandApplyBtn = document.getElementById('tb-brand-apply');
    const stockBtn = document.getElementById('tb-stock');
    const imageEditorBtn = document.getElementById('tb-image-editor');
    // WP-46 F-2.5.4-011.
    const aiImageBtn = document.getElementById('tb-ai-image');

    if (brandEditBtn) brandEditBtn.addEventListener('click', openBrandKitEditor);
    if (brandApplyBtn) brandApplyBtn.addEventListener('click', applyBrandStyles);
    if (stockBtn) stockBtn.addEventListener('click', openStockPicker);

    // WP-45 F-2.12-003 catalogue browser.
    const ecomBtn = document.getElementById('tb-ecom-products');
    if (ecomBtn) ecomBtn.addEventListener('click', openProductPicker);
    if (imageEditorBtn) imageEditorBtn.addEventListener('click', openImageEditor);
    if (aiImageBtn) aiImageBtn.addEventListener('click', openAiImagePicker);

    // Populate the "Saved blocks" category (F-2.3.3-026 / F-2.5.4-005).
    loadSavedBlocks();

    toolbar.querySelectorAll('.tb-device').forEach((btn) =>
        btn.addEventListener('click', () => editor.setDevice(btn.dataset.device))
    );

    // Toolbar-level merge-tag insert: append to the selected text component.
    const mergeTagSelect = document.getElementById('tb-merge-tags');
    if (mergeTagSelect) {
        mergeTagSelect.addEventListener('change', (ev) => {
            const tag = ev.target.value;
            ev.target.value = '';
            if (!tag) return;

            const selected = editor.getSelected();
            if (selected) {
                selected.append('{' + tag + '}');
            }
        });
    }

    /* ------------------------------------------------------------------ *
     *  Debounced autosave (F-2.5.4-008) → routes/modules/templates.php.
     *  The preset's juice command produces email-client-safe inlined HTML.
     * ------------------------------------------------------------------ */
    const inlinedHtml = () => {
        // Landing pages keep their stylesheet as a stylesheet: juice would
        // flatten the media queries the responsive layout depends on
        // (F-2.6-013), and no browser needs inlined styles.
        if (isLanding) {
            const css = editor.getCss();

            return (css ? '<style>' + css + '</style>' : '') + editor.getHtml();
        }

        try {
            const cmd = editor.Commands.get('gjs-get-inlined-html');
            if (cmd) {
                return editor.runCommand('gjs-get-inlined-html');
            }
        } catch {
            /* fall through to the non-inlined snapshot */
        }

        return '<html><head><style>' + editor.getCss() + '</style></head><body>' + editor.getHtml() + '</body></html>';
    };

    const save = () => {
        setStatus('saving');

        const body = new FormData();
        body.append('content_html', inlinedHtml());
        body.append('content_json', JSON.stringify(editor.getProjectData()));
        // Which landing document is open: the page, or one A/B arm.
        if (config.variantUid) {
            body.append('variant_uid', config.variantUid);
        }
        (Array.isArray(config.googleFonts) ? config.googleFonts : []).forEach((url) =>
            body.append('google_fonts[]', url)
        );

        fetch(config.saveUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': config.csrfToken, Accept: 'application/json' },
            body,
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((json) => {
                setStatus('saved');
                if (json.warning && statusEl) {
                    statusEl.textContent = config.i18n.saved + ' — ' + json.warning;
                }
            })
            .catch(() => setStatus('error'));
    };

    let saveTimer = null;
    const debouncedSave = () => {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(save, config.autosaveMs || 2000);
    };

    editor.on('update', debouncedSave);

    // Flush a pending autosave when leaving the page.
    window.addEventListener('beforeunload', () => {
        if (saveTimer) {
            window.clearTimeout(saveTimer);
            save();
        }
    });
}
