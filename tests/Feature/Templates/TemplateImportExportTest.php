<?php

declare(strict_types=1);

use App\Mail\TemplateTestMail;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Templates\MergeTags;
use App\Services\Templates\TemplateExporter;
use App\Services\Templates\TemplateTestSender;
use App\Services\Templates\TemplateThumbnails;
use App\Services\Templates\TemplateUrlImporter;
use App\Services\Templates\TemplateZipImporter;
use App\Services\Templates\UnsubscribeTagChecker;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);
});

/** Builds a fixture ZIP on disk; entries = name => content. Returns its path. */
function wp10_fixture_zip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'wp10-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
}

// F-2.5.5-001 — ZIP upload extracts images and rewrites relative references
it('imports a template zip and rewrites relative image references', function (): void {
    Storage::fake('public');

    $template = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);

    $zipPath = wp10_fixture_zip([
        'index.html' => '<html><body><img src="images/logo.png"><div style=\'background:url("images/bg.gif")\'>x</div><a href="{UNSUBSCRIBE_URL}">bye</a></body></html>',
        'images/logo.png' => 'PNGDATA',
        'images/bg.gif' => 'GIFDATA',
        'notes.txt' => 'ignored',
    ]);

    try {
        app(TemplateZipImporter::class)->import($template, $zipPath);
    } finally {
        @unlink($zipPath);
    }

    $dir = 'customers/'.$this->customer->uid.'/templates/'.$template->uid;

    Storage::disk('public')->assertExists($dir.'/images/logo.png');
    Storage::disk('public')->assertExists($dir.'/images/bg.gif');

    $html = $template->refresh()->content_html;
    $logoUrl = Storage::disk('public')->url($dir.'/images/logo.png');

    expect($html)->toContain('src="'.$logoUrl.'"')
        ->and($html)->toContain('url('.Storage::disk('public')->url($dir.'/images/bg.gif').')')
        ->and($html)->not->toContain('src="images/logo.png"');
});

// F-2.5.5-001 — single top-level folder archives work too
it('imports a zip whose files sit inside one top-level folder', function (): void {
    Storage::fake('public');

    $template = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);

    $zipPath = wp10_fixture_zip([
        'my-template/index.html' => '<html><body><img src="img/pic.jpg">{UNSUBSCRIBE_URL}</body></html>',
        'my-template/img/pic.jpg' => 'JPG',
    ]);

    try {
        app(TemplateZipImporter::class)->import($template, $zipPath);
    } finally {
        @unlink($zipPath);
    }

    $dir = 'customers/'.$this->customer->uid.'/templates/'.$template->uid;

    Storage::disk('public')->assertExists($dir.'/img/pic.jpg');

    expect($template->refresh()->content_html)
        ->toContain(Storage::disk('public')->url($dir.'/img/pic.jpg'));
});

// F-2.5.5-001 — malformed archives are rejected with validation errors
it('rejects zips without html and zips with traversal entries', function (): void {
    Storage::fake('public');

    $template = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $importer = app(TemplateZipImporter::class);

    $noHtml = wp10_fixture_zip(['images/logo.png' => 'PNG']);

    try {
        expect(fn () => $importer->import($template, $noHtml))
            ->toThrow(ValidationException::class);
    } finally {
        @unlink($noHtml);
    }

    $slip = wp10_fixture_zip([
        'index.html' => '<p>{UNSUBSCRIBE_URL}</p>',
        '../evil.png' => 'PNG',
    ]);

    try {
        expect(fn () => $importer->import($template, $slip))
            ->toThrow(ValidationException::class);
    } finally {
        @unlink($slip);
    }

    expect(fn () => $importer->import($template, __FILE__))
        ->toThrow(ValidationException::class);
});

// F-2.5.5-002 — import from URL absolutises relative references
it('imports a template from a url and absolutises its references', function (): void {
    Http::fake([
        'newsletters.example.com/*' => Http::response(
            '<html><body><img src="images/hero.png"><img src="/shared/logo.png"><a href="https://kept.example.com/x">x</a>{UNSUBSCRIBE_URL}</body></html>',
        ),
    ]);

    $template = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);

    app(TemplateUrlImporter::class)->import($template, 'https://newsletters.example.com/campaigns/summer.html');

    $html = $template->refresh()->content_html;

    expect($html)->toContain('src="https://newsletters.example.com/campaigns/images/hero.png"')
        ->and($html)->toContain('src="https://newsletters.example.com/shared/logo.png"')
        ->and($html)->toContain('href="https://kept.example.com/x"');
});

it('rejects unreachable, oversized and empty url imports', function (): void {
    $template = EmailTemplate::factory()->create(['customer_id' => $this->customer->id]);
    $importer = app(TemplateUrlImporter::class);

    Http::fake(['down.example.com/*' => Http::response('nope', 500)]);

    expect(fn () => $importer->import($template, 'https://down.example.com/x'))
        ->toThrow(ValidationException::class);

    config(['emp-templates.import_url.max_bytes' => 10]);
    Http::fake(['big.example.com/*' => Http::response(str_repeat('x', 100))]);

    expect(fn () => $importer->import($template, 'https://big.example.com/x'))
        ->toThrow(ValidationException::class);

    config(['emp-templates.import_url.max_bytes' => 2_097_152]);
    Http::fake(['empty.example.com/*' => Http::response('   ')]);

    expect(fn () => $importer->import($template, 'https://empty.example.com/x'))
        ->toThrow(ValidationException::class);
});

// F-2.5.5-003 — thumbnail chain: first image → placeholder; uploads never overwritten
it('derives the thumbnail from the first content image', function (): void {
    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'content_html' => '<html><body><img src="{TRACKING_PIXEL}"><img src="https://cdn.example.com/hero.png"><p>{UNSUBSCRIBE_URL}</p></body></html>',
    ]);

    app(TemplateThumbnails::class)->refreshFromContent($template);

    // merge-tag sources are skipped; the first real image wins
    expect($template->refresh()->thumbnail_path)->toBe('https://cdn.example.com/hero.png')
        ->and($template->thumbnailUrl())->toBe('https://cdn.example.com/hero.png');
});

it('keeps an explicitly uploaded thumbnail and falls back to the placeholder otherwise', function (): void {
    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'content_html' => '<p>text only {UNSUBSCRIBE_URL}</p>',
    ]);

    $thumbnails = app(TemplateThumbnails::class);

    $thumbnails->refreshFromContent($template);
    // No image to extract → no stored path; the URL is the WP-27B derived
    // SVG preview (data URI), not a screenshot and not the blank placeholder.
    expect($template->refresh()->thumbnail_path)->toBeNull()
        ->and($template->thumbnailUrl())->toStartWith('data:image/svg+xml;base64,');

    $thumbnails->setUploaded($template, 'gallery/thumbnails/custom.png');
    $template->refresh();
    $template->content_html = '<img src="https://cdn.example.com/other.png"> {UNSUBSCRIBE_URL}';
    $thumbnails->refreshFromContent($template);

    expect($template->refresh()->thumbnail_path)->toBe('gallery/thumbnails/custom.png');
});

/* ---------------------------------------------------------------------- *
 *  F-2.5.5-003 (WP-27B) — the derived preview is DERIVED and DETERMINISTIC.
 *  It is explicitly NOT a screenshot: no browser is involved, so it reports
 *  what the markup declares (colours, first heading, one bar per text block,
 *  whether an image exists) and nothing else.
 * ---------------------------------------------------------------------- */
it('derives a deterministic svg preview from the template markup', function (): void {
    $thumbnails = app(TemplateThumbnails::class);

    $html = '<html><body style="background-color:#ffffff">'
        .'<table style="background-color:#1d4ed8"><tr><td>'
        .'<h1 style="color:#111111">Black Friday</h1>'
        .'<p>Fifty percent off everything in the winter collection this weekend only.</p>'
        .'<p>Short line.</p>'
        .'</td></tr></table></body></html>';

    $svg = $thumbnails->previewSvg($html);

    expect($svg)->toStartWith('<svg xmlns="http://www.w3.org/2000/svg"')
        ->toContain('Black Friday')
        ->toContain('#ffffff')   // background: the frequent light colour
        ->toContain('#1d4ed8')   // accent: the frequent mid colour
        ->toContain('#111111');  // text: the darkest declared colour

    // Deterministic: identical markup ⇒ identical bytes.
    expect($thumbnails->previewSvg($html))->toBe($svg);

    // The longer paragraph produces a wider bar than the short one.
    preg_match_all('/<rect x="48" y="\d+" width="(\d+)" height="12"/', (string) $svg, $bars);
    expect($bars[1])->toHaveCount(2)
        ->and((int) $bars[1][0])->toBeGreaterThan((int) $bars[1][1]);

    // Palette extraction is a public, independently testable contract.
    expect($thumbnails->palette($html))->toBe([
        'background' => '#ffffff',
        'accent' => '#1d4ed8',
        'text' => '#111111',
    ]);

    // Markup that declares no colour at all falls back to card defaults,
    // and content that is nothing but merge tags yields no preview at all.
    expect($thumbnails->palette('<p>plain</p>'))->toBe([
        'background' => '#ffffff',
        'accent' => '#94a3b8',
        'text' => '#111827',
    ])
        ->and($thumbnails->previewSvg('<p>{UNSUBSCRIBE_URL}</p>'))->toBeNull()
        ->and($thumbnails->previewSvg(null))->toBeNull();
});

it('marks the presence of an image in the derived preview without fetching it', function (): void {
    $thumbnails = app(TemplateThumbnails::class);

    $withImage = (string) $thumbnails->previewSvg('<h1>Hero</h1><img src="https://cdn.example.com/a.png" alt="a">');
    $withoutImage = (string) $thumbnails->previewSvg('<h1>Hero</h1><p>Just words here.</p>');

    expect($withImage)->toContain('<circle')          // image-placeholder glyph
        ->and($withoutImage)->not->toContain('<circle')
        // The remote URL is never embedded or requested.
        ->and($withImage)->not->toContain('cdn.example.com');
});

// F-2.5.5-004 + F-2.5.5-005 — test sends are queued with sample merge data
it('queues test emails rendered with sample subscriber data', function (): void {
    Mail::fake();

    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Welcome mail',
        'content_html' => '<p>Hello {SUBSCRIBER_NAME} — {CURRENT_YEAR}</p><a href="{UNSUBSCRIBE_URL}">bye</a>',
    ]);

    app(TemplateTestSender::class)->send($template, ['a@example.com', 'b@example.com']);

    Mail::assertQueued(TemplateTestMail::class, 2);
    Mail::assertQueued(
        TemplateTestMail::class,
        fn (TemplateTestMail $mail): bool => str_contains($mail->htmlBody, 'Hello Jane Doe')
            && str_contains($mail->htmlBody, date('Y'))
            && ! str_contains($mail->htmlBody, '{SUBSCRIBER_NAME}')
            && $mail->subjectLine === __('templates.test_email.subject', ['name' => 'Welcome mail']),
    );
});

// F-2.5.5-005 — sample rendering leaves unknown tags alone
it('renders sample data and preserves unknown tags', function (): void {
    $html = app(MergeTags::class)->renderSample('<p>{SUBSCRIBER_EMAIL} {NOT_A_REAL_TAG}</p>');

    expect($html)->toContain('jane.doe@example.com')
        ->and($html)->toContain('{NOT_A_REAL_TAG}');
});

// F-2.5.5-006 — unsubscribe tag checker semantics
it('checks for the required unsubscribe tags', function (): void {
    $checker = app(UnsubscribeTagChecker::class);

    expect($checker->passes('<a href="{UNSUBSCRIBE_URL}">x</a>'))->toBeTrue()
        ->and($checker->passes('<a href="{DIRECT_UNSUBSCRIBE_URL}">x</a>'))->toBeTrue()
        ->and($checker->passes('<p>none</p>'))->toBeFalse()
        ->and($checker->passes(null))->toBeTrue() // empty drafts are not flagged
        ->and($checker->blocks('<p>none</p>'))->toBeFalse(); // warn mode by default

    config(['emp-templates.unsubscribe_check' => 'block']);

    expect($checker->blocks('<p>none</p>'))->toBeTrue()
        ->and($checker->blocks('<a href="{UNSUBSCRIBE_URL}">x</a>'))->toBeFalse();
});

// F-2.5.5-007 — export as HTML and as a self-contained ZIP
it('exports the template html and a self-contained zip', function (): void {
    Storage::fake('public');

    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Summer Sale!',
    ]);

    $disk = Storage::disk('public');
    $dir = 'customers/'.$this->customer->uid.'/templates/'.$template->uid;
    $disk->put($dir.'/images/logo.png', 'PNGDATA');

    $template->forceFill([
        'content_html' => '<html><body><img src="'.$disk->url($dir.'/images/logo.png').'">{UNSUBSCRIBE_URL}</body></html>',
    ])->save();

    $exporter = app(TemplateExporter::class);

    expect($exporter->fileName($template, 'html'))->toBe('summer-sale.html')
        ->and($exporter->html($template))->toBe($template->content_html);

    $zipPath = $exporter->zip($template);

    try {
        $zip = new ZipArchive;
        $zip->open($zipPath);

        $index = (string) $zip->getFromName('index.html');

        expect($zip->getFromName('assets/images/logo.png'))->toBe('PNGDATA')
            ->and($index)->toContain('src="assets/images/logo.png"')
            ->and($index)->not->toContain('/storage/');

        $zip->close();
    } finally {
        @unlink($zipPath);
    }
});
