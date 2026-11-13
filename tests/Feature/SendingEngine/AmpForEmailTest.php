<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\Message;
use App\Sending\MimeBuilder;
use App\Sending\Pipeline\CampaignEmailFactory;
use App\Services\Content\AmpValidator;
use App\Support\Tenancy\CurrentCustomer;

/*
 * §2.5.4 F-2.5.4-014 — AMP for Email.
 *
 * The part that actually matters is the MIME assembly, so that is what this
 * file asserts: the tree Symfony produces, part by part, in order. A client
 * picks the LAST alternative it understands, so `text/x-amp-html` MUST sit
 * before `text/html` — a client that knows AMP takes the AMP part and every
 * client that does not walks past it to the ordinary HTML.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->server = DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);
});

/* ====================================================================== *
 *  The validator
 * ====================================================================== */

it('accepts the boilerplate it ships as a starting point', function (): void {
    $validator = app(AmpValidator::class);

    expect($validator->findings($validator->skeleton()))->toBe([])
        ->and($validator->valid($validator->skeleton()))->toBeTrue()
        ->and($validator->errors($validator->skeleton()))->toBe([]);
});

it('requires every piece of amp4email boilerplate', function (): void {
    $validator = app(AmpValidator::class);
    $good = $validator->skeleton();

    $cases = [
        'missing_doctype' => str_replace('<!doctype html>', '', $good),
        'missing_amp4email' => str_replace('<html ⚡4email>', '<html>', $good),
        'missing_charset' => str_replace('<meta charset="utf-8">', '', $good),
        'missing_runtime' => str_replace('<script async src="https://cdn.ampproject.org/v0.js"></script>', '', $good),
        'missing_boilerplate' => str_replace('<style amp4email-boilerplate>body{visibility:hidden}</style>', '', $good),
        'missing_body' => str_replace(['<body>', '</body>'], '', $good),
    ];

    foreach ($cases as $rule => $broken) {
        expect(ampRules($validator->findings($broken)))->toContain($rule)
            ->and($validator->valid($broken))->toBeFalse();
    }

    // `amp4email` spelled out is equally valid — the lightning bolt is not
    // the only accepted form.
    expect($validator->valid(str_replace('<html ⚡4email>', '<html amp4email>', $good)))->toBeTrue();
});

it('rejects the scripts, styles and tags the format forbids', function (): void {
    $validator = app(AmpValidator::class);
    $insert = fn (string $markup): string => str_replace('<body>', '<body>'.$markup, $validator->skeleton());
    $head = fn (string $markup): string => str_replace('</head>', $markup.'</head>', $validator->skeleton());

    expect(ampRules($validator->findings($head('<script>alert(1)</script>'))))->toContain('inline_script')
        ->and(ampRules($validator->findings($head('<script src="https://evil.test/x.js"></script>'))))->toContain('foreign_script')
        ->and(ampRules($validator->findings($head('<link rel="stylesheet" href="https://evil.test/x.css">'))))->toContain('external_stylesheet')
        ->and(ampRules($validator->findings($head('<style>p{color:red}</style>'))))->toContain('foreign_style')
        ->and(ampRules($validator->findings($insert('<iframe src="https://x.test"></iframe>'))))->toContain('forbidden_tag')
        ->and(ampRules($validator->findings($insert('<video src="https://x.test/v.mp4"></video>'))))->toContain('forbidden_tag')
        ->and(ampRules($validator->findings($insert('<amp-img src="http://x.test/a.png"></amp-img>'))))->toContain('insecure_url');

    // <script type="application/json"> IS allowed — it is how components are
    // configured — and so is an official component script.
    $withState = $head('<script async custom-element="amp-state" src="https://cdn.ampproject.org/v0/amp-state-0.1.js"></script>');
    $withState = str_replace('<body>', '<body><amp-state id="s"><script type="application/json">{"a":1}</script></amp-state>', $withState);

    expect($validator->valid($withState))->toBeTrue();
});

it('polices the amp component allow-list and its script declarations', function (): void {
    $validator = app(AmpValidator::class);
    $insert = fn (string $markup): string => str_replace('<body>', '<body>'.$markup, $validator->skeleton());

    // amp-video is not on the AMP for Email allow-list at all.
    expect(ampRules($validator->findings($insert('<amp-video src="https://x.test/v.mp4"></amp-video>'))))
        ->toContain('forbidden_component');

    // amp-list IS allowed, but only with its component script.
    expect(ampRules($validator->findings($insert('<amp-list src="https://x.test/j"></amp-list>'))))
        ->toContain('missing_component_script');

    $declared = str_replace(
        '</head>',
        '<script async custom-element="amp-list" src="https://cdn.ampproject.org/v0/amp-list-0.1.js"></script></head>',
        $insert('<amp-list src="https://x.test/j"></amp-list>'),
    );

    expect($validator->valid($declared))->toBeTrue();

    // amp-img needs no declaration — the runtime ships it.
    expect($validator->valid($insert('<amp-img src="https://x.test/a.png" width="1" height="1"></amp-img>')))
        ->toBeTrue();
});

it('enforces the css and document size limits', function (): void {
    $validator = app(AmpValidator::class);
    $good = $validator->skeleton();

    $important = str_replace('color: #111111;', 'color: #111111 !important;', $good);
    expect(ampRules($validator->findings($important)))->toContain('css_important');

    $import = str_replace('body {', '@import url(x.css); body {', $good);
    expect(ampRules($validator->findings($import)))->toContain('css_import');

    $bigCss = str_replace(
        'body { font-family: Arial, sans-serif; color: #111111; }',
        str_repeat('.a{color:#111111;}', 5000), // > 75,000 bytes
        $good,
    );
    expect(ampRules($validator->findings($bigCss)))->toContain('custom_css_too_large');

    // Between 100 kB and 200 kB is a Gmail WARNING, not a format error.
    $warn = str_replace('<body>', '<body>'.str_repeat('<p>x</p>', 15000), $good);
    expect(strlen($warn))->toBeGreaterThan(AmpValidator::GMAIL_SOFT_LIMIT_BYTES)
        ->and(strlen($warn))->toBeLessThan(AmpValidator::MAX_DOCUMENT_BYTES)
        ->and(ampRules($validator->findings($warn)))->toContain('gmail_size_warning')
        ->and($validator->valid($warn))->toBeTrue();

    $tooBig = str_replace('<body>', '<body>'.str_repeat('<p>xxxxxxxxxx</p>', 20000), $good);
    expect(ampRules($validator->findings($tooBig)))->toContain('document_too_large')
        ->and($validator->valid($tooBig))->toBeFalse();
});

/* ====================================================================== *
 *  MIME assembly — the part that matters
 * ====================================================================== */

it('places the amp part between the plain text and the html', function (): void {
    $message = new Message(
        fromEmail: 'from@fixture.test',
        to: ['to@fixture.test'],
        subject: 'AMP',
        html: '<p>HTML body</p>',
        text: 'Plain body',
        amp: app(AmpValidator::class)->skeleton(),
    );

    $email = app(MimeBuilder::class)->build($message, $this->server);
    $body = $email->getBody();

    expect($body->getMediaType().'/'.$body->getMediaSubtype())->toBe('multipart/alternative')
        ->and(ampPartTypes($body))->toBe(['text/plain', 'text/x-amp-html', 'text/html']);

    // …and the serialised message really does carry the header, in order.
    $raw = $email->toString();
    $ampAt = strpos($raw, 'text/x-amp-html');
    $htmlAt = strpos($raw, 'text/html');

    expect($ampAt)->not->toBeFalse()
        ->and($htmlAt)->not->toBeFalse()
        ->and($ampAt)->toBeLessThan($htmlAt)
        ->and($raw)->toContain('amp4email');
});

it('assembles exactly as before when there is no amp part', function (): void {
    $plain = new Message(
        fromEmail: 'from@fixture.test',
        to: ['to@fixture.test'],
        subject: 'No AMP',
        html: '<p>HTML body</p>',
        text: 'Plain body',
    );

    $withEmptyAmp = new Message(
        fromEmail: 'from@fixture.test',
        to: ['to@fixture.test'],
        subject: 'No AMP',
        html: '<p>HTML body</p>',
        text: 'Plain body',
        amp: '   ',
    );

    $builder = app(MimeBuilder::class);

    expect(ampPartTypes($builder->build($plain, $this->server)->getBody()))
        ->toBe(['text/plain', 'text/html'])
        ->and(ampPartTypes($builder->build($withEmptyAmp, $this->server)->getBody()))
        ->toBe(['text/plain', 'text/html']);
});

it('keeps attachments and inline images around the amp part', function (): void {
    $builder = app(MimeBuilder::class);

    $withAttachment = new Message(
        fromEmail: 'from@fixture.test',
        to: ['to@fixture.test'],
        subject: 'AMP + attachment',
        html: '<p>HTML body</p>',
        text: 'Plain body',
        attachments: [['name' => 'note.txt', 'content' => 'hello', 'mime' => 'text/plain']],
        amp: app(AmpValidator::class)->skeleton(),
    );

    $body = $builder->build($withAttachment, $this->server)->getBody();

    // multipart/mixed( multipart/alternative(plain, amp, html), attachment )
    expect($body->getMediaSubtype())->toBe('mixed')
        ->and($body->getParts()[0]->getMediaSubtype())->toBe('alternative')
        ->and(ampPartTypes($body->getParts()[0]))->toBe(['text/plain', 'text/x-amp-html', 'text/html'])
        ->and(count($body->getParts()))->toBe(2);

    $withInline = new Message(
        fromEmail: 'from@fixture.test',
        to: ['to@fixture.test'],
        subject: 'AMP + cid',
        html: '<p><img src="cid:logo"></p>',
        text: 'Plain body',
        embedded: [['cid' => 'logo', 'content' => 'PNGDATA', 'mime' => 'image/png']],
        amp: app(AmpValidator::class)->skeleton(),
    );

    $related = $builder->build($withInline, $this->server)->getBody();

    // multipart/related( multipart/alternative(plain, amp, html), image )
    expect($related->getMediaSubtype())->toBe('related')
        ->and(ampPartTypes($related->getParts()[0]))->toBe(['text/plain', 'text/x-amp-html', 'text/html']);
});

/* ====================================================================== *
 *  The campaign path
 * ====================================================================== */

it('personalises the amp body and re-validates it after rendering', function (): void {
    $subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'ana@fixture.test']);

    $amp = str_replace('{FNAME|there}', '{EMAIL}', app(AmpValidator::class)->skeleton());

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'subject' => 'AMP campaign',
        'content_html' => '<p>HTML <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
        'amp_html' => $amp,
    ]);

    $message = app(CampaignEmailFactory::class)->make($campaign, $subscriber);

    expect($message->amp)->not->toBeNull()
        ->and($message->amp)->toContain('ana@fixture.test')
        ->and($message->amp)->not->toContain('{EMAIL}');

    $body = app(MimeBuilder::class)->build($message, $this->server)->getBody();

    expect(ampPartTypes($body))->toBe(['text/plain', 'text/x-amp-html', 'text/html']);
});

it('sends exactly as today when the amp body is absent, invalid or unwanted', function (): void {
    $subscriber = Subscriber::factory()->for($this->list, 'list')->create();
    $factory = app(CampaignEmailFactory::class);

    $base = [
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'subject' => 'AMP gate',
        'content_html' => '<p>HTML <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
    ];

    // 1. no AMP body.
    $none = Campaign::factory()->create($base);
    expect($factory->make($none, $subscriber)->amp)->toBeNull();

    // 2. an AMP body that does not validate.
    $broken = Campaign::factory()->create($base + ['amp_html' => '<html><body>Not AMP at all</body></html>']);
    expect($factory->make($broken, $subscriber)->amp)->toBeNull();

    // 3. a valid body on a plain-text-only campaign — an AMP alternative would
    //    contradict the customer's explicit choice.
    $plainOnly = Campaign::factory()->create(
        $base + ['amp_html' => app(AmpValidator::class)->skeleton(), 'plain_text_only' => true],
    );
    expect($factory->make($plainOnly, $subscriber)->amp)->toBeNull();

    // 4. a body that only STOPS validating once merge tags render: a tag whose
    //    value injects a forbidden tag must not reach the wire.
    $poisoned = str_replace('{FNAME|there}', '{CAMPAIGN_NAME}', app(AmpValidator::class)->skeleton());
    $late = Campaign::factory()->create($base + [
        'name' => '<iframe src="https://x.test"></iframe>',
        'amp_html' => $poisoned,
    ]);

    expect(app(AmpValidator::class)->valid($poisoned))->toBeTrue()
        ->and($factory->make($late, $subscriber)->amp)->toBeNull();
});

/* ---------------------------------------------------------------------- */

/** @return list<string> */
function ampRules(array $findings): array
{
    return array_map(static fn (array $finding): string => $finding['rule'], $findings);
}

/** Media types of a multipart part's children, in order. @return list<string> */
function ampPartTypes(object $part): array
{
    return array_map(
        static fn (object $child): string => $child->getMediaType().'/'.$child->getMediaSubtype(),
        $part->getParts(),
    );
}
