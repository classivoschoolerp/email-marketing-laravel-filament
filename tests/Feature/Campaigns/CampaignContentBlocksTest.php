<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Sending\Pipeline\CampaignEmailFactory;
use App\Services\Content\ConditionalContent;
use App\Services\Content\DarkModePreview;
use App\Services\Content\MergeTags;
use App\Services\Content\RssFeedReader;
use App\Services\Templates\Builders\Basic\BasicBlockRenderer;
use App\Services\Templates\Builders\Basic\BasicBlocks;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * §2.3.3 P2 content rows:
 *   F-2.3.3-008 conditional content blocks
 *   F-2.3.3-011 / F-2.3.3-024 RSS-to-email block + widget
 *   F-2.3.3-012 countdown timer block
 *   F-2.3.3-027 dark-mode preview
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->conditionals = app(ConditionalContent::class);
});

/* ---------------------------------------------------------------- *
 *  F-2.3.3-008 — conditional content blocks
 * ---------------------------------------------------------------- */

it('renders the spec syntax [IF FNAME = \'X\'] … [ENDIF]', function (): void {
    $content = "[IF FNAME = 'Ana']Hi Ana![ENDIF]";

    expect($this->conditionals->render($content, ['FNAME' => 'Ana']))->toBe('Hi Ana!')
        ->and($this->conditionals->render($content, ['FNAME' => 'Bob']))->toBe('');
});

it('supports an ELSE branch', function (): void {
    $content = "[IF FNAME = 'Ana']Hi Ana![ELSE]Hello there![ENDIF]";

    expect($this->conditionals->render($content, ['FNAME' => 'Ana']))->toBe('Hi Ana!')
        ->and($this->conditionals->render($content, ['FNAME' => 'Bob']))->toBe('Hello there!');
});

it('supports every documented operator', function (): void {
    $values = ['FNAME' => 'Ana', 'AGE' => '30', 'CITY' => ''];

    $cases = [
        "[IF FNAME != 'Bob']y[ENDIF]" => 'y',
        "[IF FNAME <> 'Ana']y[ENDIF]" => '',
        "[IF FNAME contains 'na']y[ENDIF]" => 'y',
        "[IF FNAME not contains 'zz']y[ENDIF]" => 'y',
        "[IF FNAME starts with 'An']y[ENDIF]" => 'y',
        "[IF FNAME ends with 'na']y[ENDIF]" => 'y',
        '[IF AGE > 20]y[ENDIF]' => 'y',
        '[IF AGE < 20]y[ENDIF]' => '',
        '[IF AGE >= 30]y[ENDIF]' => 'y',
        '[IF AGE <= 29]y[ENDIF]' => '',
        '[IF CITY is empty]y[ENDIF]' => 'y',
        '[IF CITY is not empty]y[ENDIF]' => '',
        '[IF FNAME is not empty]y[ENDIF]' => 'y',
    ];

    foreach ($cases as $source => $expected) {
        expect($this->conditionals->render($source, $values))->toBe($expected, $source);
    }
});

it('treats an unknown tag as empty in both directions', function (): void {
    expect($this->conditionals->render("[IF NOPE = 'x']y[ENDIF]", []))->toBe('')
        ->and($this->conditionals->render('[IF NOPE is empty]y[ENDIF]', []))->toBe('y');
});

it('resolves nested conditional blocks innermost first', function (): void {
    $content = "[IF A = '1']outer[IF B = '2'] inner[ENDIF] end[ENDIF]";

    expect($this->conditionals->render($content, ['A' => '1', 'B' => '2']))->toBe('outer inner end')
        ->and($this->conditionals->render($content, ['A' => '1', 'B' => '9']))->toBe('outer end')
        ->and($this->conditionals->render($content, ['A' => '9', 'B' => '2']))->toBe('');
});

it('detects unbalanced markers for the preflight', function (): void {
    expect($this->conditionals->isBalanced("[IF A = '1']x[ENDIF]"))->toBeTrue()
        ->and($this->conditionals->isBalanced("[IF A = '1']x"))->toBeFalse()
        ->and($this->conditionals->has("[IF A = '1']x[ENDIF]"))->toBeTrue()
        ->and($this->conditionals->has('plain text'))->toBeFalse();
});

// The engine runs INSIDE MergeTags::render, so every surface gets it.
it('resolves conditionals on the real send path with subscriber data', function (): void {
    $field = ListField::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'tag' => 'FNAME',
    ]);

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'subject' => 'Hi',
        'content_html' => "<html><body>[IF FNAME = 'Ana']<p>Hi Ana</p>[ELSE]<p>Hi friend</p>[ENDIF]</body></html>",
    ]);

    $ana = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'ana@x.test',
    ]);

    SubscriberFieldValue::query()->create([
        'customer_id' => $this->customer->id,
        'subscriber_id' => $ana->id,
        'list_field_id' => $field->id,
        'value' => 'Ana',
    ]);

    $other = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'other@x.test',
    ]);

    $factory = app(CampaignEmailFactory::class);

    expect($factory->make($campaign, $ana)->html)->toContain('Hi Ana')
        ->and($factory->make($campaign, $ana)->html)->not->toContain('Hi friend')
        ->and($factory->make($campaign, $other)->html)->toContain('Hi friend')
        ->and($factory->make($campaign, $other)->html)->not->toContain('Hi Ana');
});

it('leaves content without conditional syntax byte-identical', function (): void {
    $html = '<p>Nothing special {FNAME} here</p>';

    expect(app(MergeTags::class)->render($html, ['FNAME' => 'Ana']))
        ->toBe('<p>Nothing special Ana here</p>');
});

/* ---------------------------------------------------------------- *
 *  F-2.3.3-011 / F-2.3.3-024 — RSS block
 * ---------------------------------------------------------------- */

it('parses an RSS 2.0 feed into plain-text items', function (): void {
    $xml = <<<'XML'
    <?xml version="1.0"?>
    <rss version="2.0"><channel>
      <title>Acme blog</title>
      <item>
        <title>First post</title>
        <link>https://acme.test/1</link>
        <description><![CDATA[<p>Body <b>one</b></p>]]></description>
        <pubDate>Mon, 10 Aug 2026 09:00:00 +0000</pubDate>
      </item>
      <item>
        <title>Second post</title>
        <link>https://acme.test/2</link>
        <description>Body two</description>
        <pubDate>Tue, 11 Aug 2026 09:00:00 +0000</pubDate>
      </item>
      <item><title>Third</title><link>https://acme.test/3</link></item>
    </channel></rss>
    XML;

    $feed = app(RssFeedReader::class)->parse($xml, 2);

    expect($feed['title'])->toBe('Acme blog')
        ->and($feed['items'])->toHaveCount(2)
        ->and($feed['items'][0]['title'])->toBe('First post')
        ->and($feed['items'][0]['url'])->toBe('https://acme.test/1')
        // Markup from a third-party feed never survives as markup.
        ->and($feed['items'][0]['excerpt'])->toBe('Body one')
        ->and($feed['items'][0]['date'])->toBe('2026-08-10')
        ->and($feed['error'])->toBeNull();
});

it('parses an Atom feed', function (): void {
    $xml = <<<'XML'
    <?xml version="1.0"?>
    <feed xmlns="http://www.w3.org/2005/Atom">
      <title>Atom blog</title>
      <entry>
        <title>Atom post</title>
        <link rel="alternate" href="https://atom.test/1"/>
        <summary>Atom summary</summary>
        <updated>2026-08-09T10:00:00Z</updated>
      </entry>
    </feed>
    XML;

    $feed = app(RssFeedReader::class)->parse($xml, 5);

    expect($feed['title'])->toBe('Atom blog')
        ->and($feed['items'])->toHaveCount(1)
        ->and($feed['items'][0]['url'])->toBe('https://atom.test/1')
        ->and($feed['items'][0]['excerpt'])->toBe('Atom summary')
        ->and($feed['items'][0]['date'])->toBe('2026-08-09');
});

it('reports an unreachable or unparseable feed instead of pretending', function (): void {
    expect(app(RssFeedReader::class)->parse('not xml at all')['error'])->toBe('unparseable')
        ->and(app(RssFeedReader::class)->parse('')['error'])->toBe('empty')
        ->and(app(RssFeedReader::class)->read('ftp://nope', 3)['error'])->toBe('invalid_url');

    Http::fake(['*' => Http::response('', 500)]);

    expect(app(RssFeedReader::class)->read('https://down.test/feed', 3)['error'])->toBe('http_500');
});

it('renders the RSS block into the email html', function (): void {
    Http::fake(['*' => Http::response(<<<'XML'
    <?xml version="1.0"?>
    <rss version="2.0"><channel><title>Feed</title>
      <item><title>Post one</title><link>https://acme.test/1</link><description>Excerpt one</description><pubDate>Mon, 10 Aug 2026 09:00:00 +0000</pubDate></item>
    </channel></rss>
    XML, 200)]);

    $block = BasicBlocks::make(BasicBlocks::TYPE_RSS);
    $block['url'] = 'https://acme.test/feed.xml';
    $block['items'] = 3;

    $html = app(BasicBlockRenderer::class)->render([$block]);

    expect($html)->toContain('Post one')
        ->and($html)->toContain('https://acme.test/1')
        ->and($html)->toContain('Excerpt one')
        ->and($html)->toContain('2026-08-10')
        // Email-safe: tables only, no divs in the block itself.
        ->and($html)->toContain('role="presentation"');
});

it('says so when the RSS feed cannot be read', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    $block = BasicBlocks::make(BasicBlocks::TYPE_RSS);
    $block['url'] = 'https://down.test/feed.xml';

    expect(app(BasicBlockRenderer::class)->render([$block]))
        ->toContain(__('campaigns.blocks.rss_unavailable'));
});

it('normalises the rss block fields', function (): void {
    $normalised = BasicBlocks::normalize([[
        'type' => 'rss',
        'url' => 'javascript:alert(1)',
        'items' => 500,
    ]]);

    expect($normalised[0]['url'])->toBe('')
        ->and($normalised[0]['items'])->toBe(20);
});

/* ---------------------------------------------------------------- *
 *  F-2.3.3-012 — countdown timer block
 * ---------------------------------------------------------------- */

it('renders the countdown block with the remaining time at build time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

    $block = BasicBlocks::make(BasicBlocks::TYPE_COUNTDOWN);
    $block['until'] = '2026-08-15 13:30';
    $block['label'] = 'Sale ends in';

    $html = app(BasicBlockRenderer::class)->render([$block]);

    // 3 days, 3 hours, 30 minutes.
    expect($html)->toContain('Sale ends in')
        ->and($html)->toContain('>3</div>')
        ->and($html)->toContain('>30</div>')
        ->and($html)->toContain(__('campaigns.blocks.countdown_days'));
});

it('renders the expired label once the countdown has passed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00', 'UTC'));

    $block = BasicBlocks::make(BasicBlocks::TYPE_COUNTDOWN);
    $block['until'] = '2026-08-15 13:30';
    $block['expired_label'] = 'Too late!';

    expect(app(BasicBlockRenderer::class)->render([$block]))->toContain('Too late!');
});

it('normalises the countdown target to UTC and rejects junk', function (): void {
    $normalised = BasicBlocks::normalize([
        ['type' => 'countdown', 'until' => '2026-08-15T13:30:00Z'],
        ['type' => 'countdown', 'until' => 'not a date'],
    ]);

    expect($normalised[0]['until'])->toBe('2026-08-15 13:30')
        ->and($normalised[1]['until'])->toBe('');
});

it('offers the new block types in the builder menu', function (): void {
    expect(BasicBlocks::TYPES)->toContain('rss')
        ->and(BasicBlocks::TYPES)->toContain('countdown')
        ->and(BasicBlocks::typeOptions())->toHaveKey('rss')
        ->and(BasicBlocks::typeOptions())->toHaveKey('countdown');
});

/* ---------------------------------------------------------------- *
 *  F-2.3.3-027 — dark-mode preview
 * ---------------------------------------------------------------- */

it('simulates a forced-dark client', function (): void {
    $html = '<html><head><title>t</title></head><body><p>Hi</p></body></html>';

    $dark = app(DarkModePreview::class)->simulate($html, DarkModePreview::MODE_FORCED);

    expect($dark)->toContain('data-dark-preview')
        ->and($dark)->toContain('invert(1)')
        // Images are counter-inverted so photos are not negatives.
        ->and($dark)->toContain('img,video,picture')
        ->and($dark)->toContain('<p>Hi</p>');
});

it('applies the author own prefers-color-scheme block in author mode', function (): void {
    $html = '<html><head><style>@media (prefers-color-scheme: dark) { body { background: #000; } }</style></head>'
        .'<body><p>Hi</p></body></html>';

    $dark = app(DarkModePreview::class)->simulate($html, DarkModePreview::MODE_AUTHOR);

    expect($dark)->not->toContain('prefers-color-scheme')
        ->and($dark)->toContain('body { background: #000; }')
        ->and($dark)->toContain('data-dark-preview');
});

it('leaves empty content alone', function (): void {
    expect(app(DarkModePreview::class)->simulate(''))->toBe('');
});
