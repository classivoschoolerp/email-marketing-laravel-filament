<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Campaigns\CampaignPreflight;
use App\Services\Content\AccessibilityChecker;
use App\Services\Content\ColorContrast;
use App\Support\Tenancy\CurrentCustomer;

/*
 * §2.3.5 F-2.3.5-007 — accessibility check (alt text, contrast).
 *
 * THE CONTRAST NUMBERS BELOW ARE HAND-COMPUTED from the WCAG 2.1 definition,
 * not read off the implementation. The three exact cases are exact because
 * an 8-bit channel of 255 linearises to exactly 1.0 and 0 to exactly 0.0:
 *
 *   L(white) = 0.2126·1 + 0.7152·1 + 0.0722·1 = 1.0
 *   L(black) = 0
 *   L(red #ff0000) = 0.2126·1 + 0.7152·0 + 0.0722·0 = 0.2126
 *   L(blue #0000ff) = 0.0722
 *
 *   black on white = (1 + 0.05) / (0 + 0.05)      = 21          exactly
 *   white on red   = (1 + 0.05) / (0.2126 + 0.05) = 1.05/0.2626 = 3.998476…
 *   white on blue  = (1 + 0.05) / (0.0722 + 0.05) = 1.05/0.1222 = 8.592471…
 *
 * The mid-grey case needs the transfer function:
 *
 *   #808080 → cs = 128/255 = 0.501960784…
 *           → c  = ((0.501960784 + 0.055) / 1.055)^2.4
 *                = 0.527924867…^2.4 = 0.215860500…
 *   L(#808080) = 0.215860500  (grey: all three channels equal, weights sum 1)
 *   ratio vs white = 1.05 / 0.265860500 = 3.949439…
 *
 * 3.949… is BELOW the 4.5 AA minimum for normal text and ABOVE the 3.0
 * minimum for large text — which is exactly the pair of assertions that
 * proves the size rule is applied and not hard-coded.
 */

/* ====================================================================== *
 *  The WCAG maths
 * ====================================================================== */

it('computes WCAG relative luminance and contrast to the hand-computed values', function (): void {
    $contrast = app(ColorContrast::class);

    // Luminance of the pure primaries is the coefficient itself.
    expect($contrast->relativeLuminance([255, 255, 255]))->toBe(1.0)
        ->and($contrast->relativeLuminance([0, 0, 0]))->toBe(0.0)
        ->and(abs($contrast->relativeLuminance([255, 0, 0]) - 0.2126))->toBeLessThan(1e-12)
        ->and(abs($contrast->relativeLuminance([0, 255, 0]) - 0.7152))->toBeLessThan(1e-12)
        ->and(abs($contrast->relativeLuminance([0, 0, 255]) - 0.0722))->toBeLessThan(1e-12)
        // The knee of the transfer curve, computed by hand above.
        ->and(abs($contrast->relativeLuminance([128, 128, 128]) - 0.2158605001))->toBeLessThan(1e-9);

    expect($contrast->ratio([0, 0, 0], [255, 255, 255]))->toBe(21.0)
        // Order-independent, as the definition requires.
        ->and($contrast->ratio([255, 255, 255], [0, 0, 0]))->toBe(21.0)
        ->and($contrast->ratio([17, 17, 17], [17, 17, 17]))->toBe(1.0)
        ->and(abs($contrast->ratio([255, 255, 255], [255, 0, 0]) - 1.05 / 0.2626))->toBeLessThan(1e-12)
        ->and(abs($contrast->ratio([255, 255, 255], [255, 0, 0]) - 3.9984767707))->toBeLessThan(1e-9)
        ->and(abs($contrast->ratio([255, 255, 255], [0, 0, 255]) - 1.05 / 0.1222))->toBeLessThan(1e-12)
        ->and(abs($contrast->ratio([255, 255, 255], [0, 0, 255]) - 8.5924713584))->toBeLessThan(1e-9)
        ->and(abs($contrast->ratio([128, 128, 128], [255, 255, 255]) - 3.9494396480))->toBeLessThan(1e-9);
});

it('applies the AA thresholds at the WCAG size boundaries', function (): void {
    $contrast = app(ColorContrast::class);

    // 18 pt = 24 px, 14 pt bold = 18.666… px (CSS reference 96 dpi).
    expect($contrast->required(23.9, false))->toBe(ColorContrast::AA_NORMAL)
        ->and($contrast->required(24.0, false))->toBe(ColorContrast::AA_LARGE)
        ->and($contrast->required(18.0, true))->toBe(ColorContrast::AA_NORMAL)
        ->and($contrast->required(18.7, true))->toBe(ColorContrast::AA_LARGE)
        ->and($contrast->required(18.7, false))->toBe(ColorContrast::AA_NORMAL);
});

it('parses the colour notations it claims and refuses the ones it cannot', function (): void {
    $contrast = app(ColorContrast::class);

    expect($contrast->parse('#fff'))->toBe([255, 255, 255])
        ->and($contrast->parse('#AbC'))->toBe([170, 187, 204])
        ->and($contrast->parse('#336699'))->toBe([51, 102, 153])
        ->and($contrast->parse('#336699ff'))->toBe([51, 102, 153])
        ->and($contrast->parse('rgb(51, 102, 153)'))->toBe([51, 102, 153])
        ->and($contrast->parse('rgb(51 102 153)'))->toBe([51, 102, 153])
        ->and($contrast->parse('rgba(51,102,153,1)'))->toBe([51, 102, 153])
        // hsl(0,100%,50%) is pure red; hsl(120,100%,25%) is the named `green`.
        ->and($contrast->parse('hsl(0, 100%, 50%)'))->toBe([255, 0, 0])
        ->and($contrast->parse('hsl(120,100%,25%)'))->toBe([0, 128, 0])
        ->and($contrast->parse('rebeccapurple'))->toBe([102, 51, 153])
        ->and($contrast->parse('WHITE'))->toBe([255, 255, 255]);

    // Everything that cannot be resolved statically is NULL, never a guess.
    expect($contrast->parse('rgba(0,0,0,0.5)'))->toBeNull()
        ->and($contrast->parse('#33669980'))->toBeNull()
        ->and($contrast->parse('transparent'))->toBeNull()
        ->and($contrast->parse('currentColor'))->toBeNull()
        ->and($contrast->parse('var(--brand)'))->toBeNull()
        ->and($contrast->parse('linear-gradient(#fff, #000)'))->toBeNull()
        ->and($contrast->parse('notacolour'))->toBeNull()
        ->and($contrast->parse(null))->toBeNull();

    expect($contrast->isTransparent('transparent'))->toBeTrue()
        ->and($contrast->isTransparent('rgba(1,2,3,0)'))->toBeTrue()
        ->and($contrast->isTransparent('#ffffff'))->toBeFalse();
});

/* ====================================================================== *
 *  The checker
 * ====================================================================== */

it('flags a low-contrast text run with the hand-computed ratio', function (): void {
    $findings = app(AccessibilityChecker::class)->check(
        '<html lang="en"><body style="background-color:#ffffff">'
        .'<p style="color:#808080">Grey on white, 16 px</p>'
        .'</body></html>',
    );

    $contrast = accessibilityRule($findings, AccessibilityChecker::RULE_CONTRAST_LOW);

    expect($contrast)->not->toBeNull()
        ->and($contrast->severity)->toBe(AccessibilityChecker::SEVERITY_ERROR)
        // round(3.949439…, 2) = 3.95, and the AA minimum for 16 px text is 4.5.
        ->and($contrast->context['ratio'])->toBe(3.95)
        ->and($contrast->context['required'])->toBe(4.5)
        ->and($contrast->context['foreground'])->toBe('#808080')
        ->and($contrast->context['background'])->toBe('#ffffff')
        ->and($contrast->excerpt)->toContain('Grey on white');
});

it('passes the same colours once the text is large enough', function (): void {
    // 3.949… clears the 3.0 large-text minimum at 24 px.
    $large = app(AccessibilityChecker::class)->check(
        '<html lang="en"><body style="background-color:#fff">'
        .'<p style="color:#808080;font-size:24px">Grey on white, 24 px</p>'
        .'</body></html>',
    );

    expect(accessibilityRule($large, AccessibilityChecker::RULE_CONTRAST_LOW))->toBeNull();

    // …and 23 px does not.
    $small = app(AccessibilityChecker::class)->check(
        '<html lang="en"><body style="background-color:#fff">'
        .'<p style="color:#808080;font-size:23px">Grey on white, 23 px</p>'
        .'</body></html>',
    );

    expect(accessibilityRule($small, AccessibilityChecker::RULE_CONTRAST_LOW))->not->toBeNull();

    // Bold moves the boundary to 18.67 px, exactly as WCAG says.
    $bold = app(AccessibilityChecker::class)->check(
        '<html lang="en"><body style="background-color:#fff">'
        .'<p style="color:#808080;font-size:19px;font-weight:700">Bold grey</p>'
        .'</body></html>',
    );

    expect(accessibilityRule($bold, AccessibilityChecker::RULE_CONTRAST_LOW))->toBeNull();
});

it('inherits colour down the tree and background from the nearest painted ancestor', function (): void {
    // The <td> paints #000000; the <span> inherits it and declares white text
    // ⇒ 21:1, a pass. The sibling paragraph outside the dark cell is black on
    // the white body ⇒ also a pass. Nothing should be reported.
    $findings = app(AccessibilityChecker::class)->check(
        '<html lang="en"><body bgcolor="#ffffff">'
        .'<table role="presentation"><tr><td bgcolor="#000000">'
        .'<span style="color:#ffffff">White on black</span>'
        .'</td></tr></table>'
        .'<p>Default black on the body background</p>'
        .'</body></html>',
    );

    expect(accessibilityRule($findings, AccessibilityChecker::RULE_CONTRAST_LOW))->toBeNull()
        ->and(accessibilityRule($findings, AccessibilityChecker::RULE_CONTRAST_UNRESOLVED))->toBeNull();

    // Swap only the span's colour: now black on black, ratio 1:1.
    $broken = app(AccessibilityChecker::class)->check(
        '<html lang="en"><body bgcolor="#ffffff">'
        .'<table role="presentation"><tr><td bgcolor="#000000">'
        .'<span style="color:#000000">Black on black</span>'
        .'</td></tr></table></body></html>',
    );

    expect(accessibilityRule($broken, AccessibilityChecker::RULE_CONTRAST_LOW)->context['ratio'])->toBe(1.0);
});

it('reports unresolvable colours instead of passing them', function (): void {
    $checker = app(AccessibilityChecker::class);

    $cases = [
        'background_image' => '<p style="background:url(hero.png);color:#333">On an image</p>',
        'gradient' => '<p style="background:linear-gradient(#fff,#000);color:#333">On a gradient</p>',
        'alpha' => '<p style="color:rgba(0,0,0,0.4)">Half-transparent text</p>',
        'css_variable' => '<p style="color:var(--brand)">Variable text</p>',
        'unknown_colour' => '<p style="color:brandblue">Unknown name</p>',
    ];

    foreach ($cases as $reason => $body) {
        $finding = accessibilityRule(
            $checker->check('<html lang="en"><body>'.$body.'</body></html>'),
            AccessibilityChecker::RULE_CONTRAST_UNRESOLVED,
        );

        expect($finding)->not->toBeNull("expected an unresolved finding for {$reason}")
            ->and($finding->severity)->toBe(AccessibilityChecker::SEVERITY_NOTICE)
            ->and($finding->context['reason'])->toBe($reason);
    }
});

it('resolves simple style-block rules and admits the selectors it skipped', function (): void {
    $findings = app(AccessibilityChecker::class)->check(<<<'HTML'
        <html lang="en">
        <head><style>
            body { background-color: #ffffff; }
            .faint { color: #cccccc; }
            #shout { color: #000000; }
            .wrap .faint span { color: #000000; }
            @media (prefers-color-scheme: dark) { .faint { color: #ffffff; } }
        </style></head>
        <body><div class="wrap"><p class="faint">Faint text</p><p id="shout">Loud text</p></div></body>
        </html>
        HTML);

    // #cccccc on #ffffff: L(#ccc) = 0.6104…, ratio = 1.05/0.6604… = 1.59 — a fail.
    $contrast = accessibilityRule($findings, AccessibilityChecker::RULE_CONTRAST_LOW);

    expect($contrast)->not->toBeNull()
        ->and($contrast->context['foreground'])->toBe('#cccccc')
        ->and($contrast->context['background'])->toBe('#ffffff')
        ->and($contrast->excerpt)->toContain('Faint text');

    // The descendant selector and the @media block were NOT applied, and the
    // checker says so rather than pretending the analysis was complete.
    $skipped = accessibilityRule($findings, AccessibilityChecker::RULE_CSS_NOT_PARSED);

    expect($skipped)->not->toBeNull()
        ->and($skipped->severity)->toBe(AccessibilityChecker::SEVERITY_NOTICE)
        ->and($skipped->context['selectors'])->toBe(2);
});

it('applies the alt-text rules in both directions', function (): void {
    $checker = app(AccessibilityChecker::class);

    $findings = $checker->check(
        '<html lang="en"><body>'
        .'<img src="hero.png">'                                   // missing alt   → error
        .'<img src="logo.png" alt="Acme logo">'                    // fine
        .'<img src="divider.png" alt="">'                          // empty alt     → notice
        .'<img src="spacer.gif" width="1" height="1" alt="spacer">' // decorative    → notice
        .'<img src="tracking-pixel.gif" alt="">'                   // decorative, correct
        .'</body></html>',
    );

    $rules = array_map(fn ($f): string => $f->rule, $findings);

    expect($rules)->toContain(AccessibilityChecker::RULE_IMG_MISSING_ALT)
        ->and($rules)->toContain(AccessibilityChecker::RULE_IMG_EMPTY_ALT)
        ->and($rules)->toContain(AccessibilityChecker::RULE_IMG_DECORATIVE_ALT);

    $counts = $checker->counts();

    expect($counts[AccessibilityChecker::RULE_IMG_MISSING_ALT])->toBe(1)
        ->and($counts[AccessibilityChecker::RULE_IMG_EMPTY_ALT])->toBe(1)
        ->and($counts[AccessibilityChecker::RULE_IMG_DECORATIVE_ALT])->toBe(1);

    expect(accessibilityRule($findings, AccessibilityChecker::RULE_IMG_MISSING_ALT)?->severity)
        ->toBe(AccessibilityChecker::SEVERITY_ERROR)
        ->and(accessibilityRule($findings, AccessibilityChecker::RULE_IMG_MISSING_ALT)?->excerpt)
        ->toContain('hero.png');
});

it('applies the link, heading, table and font-size rules', function (): void {
    $checker = app(AccessibilityChecker::class);

    $findings = $checker->check(<<<'HTML'
        <html lang="en"><body>
            <h1>Newsletter</h1>
            <h3>Skipped a level</h3>
            <p><a href="https://example.com/report">Click here</a></p>
            <p><a href="https://example.com/may">Read the May report</a></p>
            <p><a href="https://example.com/empty"><img src="i.png" alt=""></a></p>
            <p style="font-size:10px">Tiny legal text</p>
            <table><tr><td>Layout cell</td></tr></table>
            <table role="presentation"><tr><td>Declared layout</td></tr></table>
            <table><caption>Real data</caption><tr><th>Month</th></tr></table>
        </body></html>
        HTML);

    $counts = $checker->counts();

    expect($counts[AccessibilityChecker::RULE_HEADING_ORDER] ?? 0)->toBe(1)
        ->and($counts[AccessibilityChecker::RULE_LINK_GENERIC_TEXT] ?? 0)->toBe(1)
        ->and($counts[AccessibilityChecker::RULE_LINK_NO_TEXT] ?? 0)->toBe(1)
        ->and($counts[AccessibilityChecker::RULE_SMALL_FONT] ?? 0)->toBe(1)
        // Only the first table is layout markup without a role.
        ->and($counts[AccessibilityChecker::RULE_LAYOUT_TABLE] ?? 0)->toBe(1);

    $heading = accessibilityRule($findings, AccessibilityChecker::RULE_HEADING_ORDER);
    expect($heading->context)->toBe(['from' => 1, 'to' => 3]);

    $font = accessibilityRule($findings, AccessibilityChecker::RULE_SMALL_FONT);
    expect($font->context['size_px'])->toBe(10.0)
        ->and($font->context['minimum_px'])->toBe(AccessibilityChecker::MIN_FONT_PX);

    $link = accessibilityRule($findings, AccessibilityChecker::RULE_LINK_GENERIC_TEXT);
    expect($link->context['text'])->toBe('Click here')
        ->and($link->severity)->toBe(AccessibilityChecker::SEVERITY_WARNING);
});

it('reports a document with no language and starts heading order at h1', function (): void {
    $checker = app(AccessibilityChecker::class);

    expect(accessibilityRule($checker->check('<html><body><p>Hi</p></body></html>'), AccessibilityChecker::RULE_MISSING_LANG))
        ->not->toBeNull();

    // A bare fragment has no <html> at all — still a missing language.
    expect(accessibilityRule($checker->check('<p>Hi</p>'), AccessibilityChecker::RULE_MISSING_LANG))
        ->not->toBeNull();

    expect(accessibilityRule($checker->check('<html lang="en"><body><p>Hi</p></body></html>'), AccessibilityChecker::RULE_MISSING_LANG))
        ->toBeNull();

    // A document that opens at h2 is reported as from 0.
    $jump = accessibilityRule(
        $checker->check('<html lang="fr"><body><h2>Deuxième</h2></body></html>'),
        AccessibilityChecker::RULE_HEADING_ORDER,
    );

    expect($jump->context)->toBe(['from' => 0, 'to' => 2]);
});

it('ignores markup a client never renders', function (): void {
    // The F-2.3.2-009 preheader block is display:none — reporting its
    // (deliberately invisible) contrast would be noise on every campaign.
    $findings = app(AccessibilityChecker::class)->check(
        '<html lang="en"><body>'
        .'<div style="display:none;max-height:0;overflow:hidden;">Hidden preview text</div>'
        .'<span aria-hidden="true"><img src="deco.png"></span>'
        .'<p>Visible</p></body></html>',
    );

    expect($findings)->toBe([]);
});

it('leaves an empty body alone and survives malformed html', function (): void {
    $checker = app(AccessibilityChecker::class);

    expect($checker->check(''))->toBe([])
        ->and($checker->check('   '))->toBe([]);

    // Unclosed tags, stray entities, CJK + emoji: no exception, no crash.
    $findings = $checker->check('<html lang="ja"><body><p>日本語 🎉 <b>太字<img src="x.png"></body>');

    expect($findings)->toBeArray()
        ->and(accessibilityRule($findings, AccessibilityChecker::RULE_IMG_MISSING_ALT))->not->toBeNull();
});

it('caps findings per rule but keeps counting them', function (): void {
    $html = '<html lang="en"><body>'.str_repeat('<img src="a.png">', 20).'</body></html>';

    $checker = app(AccessibilityChecker::class);
    $findings = $checker->check($html);

    $listed = count(array_filter($findings, fn ($f): bool => $f->rule === AccessibilityChecker::RULE_IMG_MISSING_ALT));

    expect($listed)->toBe(AccessibilityChecker::MAX_PER_RULE)
        ->and($checker->counts()[AccessibilityChecker::RULE_IMG_MISSING_ALT])->toBe(20);
});

it('summarises by severity and refuses to pass an error-level document', function (): void {
    $checker = app(AccessibilityChecker::class);

    $clean = '<html lang="en"><body><p>All good</p></body></html>';
    $broken = '<html lang="en"><body><img src="x.png"></body></html>';

    expect($checker->passes($clean))->toBeTrue()
        ->and($checker->passes($broken))->toBeFalse()
        ->and($checker->summary($checker->check($broken)))
        ->toBe(['error' => 1, 'warning' => 0, 'notice' => 0]);
});

/* ====================================================================== *
 *  Wiring: preflight + the preview panel
 * ====================================================================== */

it('surfaces the accessibility pass in the campaign pre-flight checklist', function (): void {
    $customer = Customer::factory()->create();
    test()->actingAs($customer, 'customer');
    app(CurrentCustomer::class)->set($customer);

    $list = EmailList::factory()->create(['customer_id' => $customer->id]);

    $campaign = Campaign::factory()->create([
        'customer_id' => $customer->id,
        'email_list_id' => $list->id,
        'subject' => 'Accessible?',
        'send_immediately' => true,
        'content_html' => '<html lang="en"><body><img src="hero.png">'
            .'<p style="color:#cccccc">Faint</p>'
            .'<a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></body></html>',
    ]);

    $preflight = app(CampaignPreflight::class);
    $check = collect($preflight->checks($campaign))->firstWhere('key', 'accessibility');

    expect($check)->not->toBeNull()
        ->and($check['passed'])->toBeFalse()
        ->and($check['level'])->toBe(CampaignPreflight::LEVEL_WARNING)
        // Two errors (no alt, contrast 1.59 on the faint paragraph).
        ->and($check['detail'])->toBe(__('accessibility.summary', [
            'errors' => 2, 'warnings' => 0, 'notices' => 0,
        ]))
        // A warning never blocks the send — that is the documented choice.
        ->and($preflight->passes($campaign))->toBeTrue();

    // Fixed content passes the row.
    $campaign->update([
        'content_html' => '<html lang="en"><body><img src="hero.png" alt="Hero">'
            .'<p style="color:#333333">Readable</p>'
            .'<a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></body></html>',
    ]);

    $check = collect($preflight->checks($campaign->fresh()))->firstWhere('key', 'accessibility');

    expect($check['passed'])->toBeTrue()
        ->and($check['detail'])->toBeNull();
});

it('renders the accessibility panel inside the preview view', function (): void {
    $html = view('filament.customer.campaigns.preview', [
        'html' => '<html lang="en"><body><img src="hero.png"><p style="color:#808080">Grey</p></body></html>',
    ])->render();

    expect($html)->toContain(__('accessibility.title'))
        ->toContain(__('accessibility.rules.img_missing_alt.label'))
        ->toContain(__('accessibility.rules.contrast_low.label'))
        // The measured ratio reaches the customer, not just a verdict.
        ->toContain(__('accessibility.context.ratio', ['ratio' => 3.95, 'required' => 4.5]))
        // Blade escapes the `<style>` the scope caption mentions.
        ->toContain(e(__('accessibility.scope')));

    $clean = view('filament.customer.campaigns.preview', [
        'html' => '<html lang="en"><body><p>All good</p></body></html>',
    ])->render();

    expect($clean)->toContain(__('accessibility.clean'));
});

/* ---------------------------------------------------------------------- */

/** First finding for a rule, or null. */
function accessibilityRule(array $findings, string $rule): ?App\Services\Content\AccessibilityFinding
{
    foreach ($findings as $finding) {
        if ($finding->rule === $rule) {
            return $finding;
        }
    }

    return null;
}
