<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Sending\Pipeline\CampaignEmailFactory;
use App\Services\Content\CampaignPreviewRenderer;
use App\Services\Content\ConditionalContent;
use App\Services\Content\MergeTags;
use App\Services\Content\SegmentContent;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\DB;

/*
 * §2.3.3 F-2.3.3-009 — dynamic content by segment ("smart content").
 *
 * Fixture: a CITY custom field, a "VIPs" segment of everyone in Casablanca,
 * one member (Ana) and one non-member (Ben).
 */

beforeEach(function (): void {
    SegmentContent::flushResolved();

    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();

    $field = ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'CITY',
        'type' => ListFieldType::Text,
    ]);

    $this->segment = ListSegment::factory()->for($this->list, 'list')->create([
        'name' => 'VIPs',
        'match_type' => 'all',
    ]);
    $this->segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'field',
        'list_field_id' => $field->id,
        'operator' => 'is',
        'value' => 'Casablanca',
        'sort_order' => 0,
    ]);

    $this->member = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'ana@fixture.test']);
    $this->outsider = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'ben@fixture.test']);

    app(FieldValueService::class)->set($this->member, $field, 'Casablanca');
    app(FieldValueService::class)->set($this->outsider, $field, 'Rabat');

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'subject' => 'Smart content',
        'from_name' => 'Fixture',
        'content_html' => '<p>Body <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
    ]);

    $this->render = function (string $content, ?Subscriber $subscriber): string {
        return app(MergeTags::class)->render(
            $content,
            app(MergeTags::class)->values($this->campaign, $this->list, $subscriber),
        );
    };
});

/* ====================================================================== *
 *  The predicate
 * ====================================================================== */

it('renders the segment branch each recipient belongs in', function (): void {
    $content = "[IF IN SEGMENT 'VIPs']Enjoy 20% off[ELSE]Browse the range[ENDIF]";

    expect(($this->render)($content, $this->member))->toBe('Enjoy 20% off')
        ->and(($this->render)($content, $this->outsider))->toBe('Browse the range');
});

it('inverts with NOT IN SEGMENT', function (): void {
    $content = "[IF NOT IN SEGMENT 'VIPs']Join the VIP list[ELSE]Thanks for being a VIP[ENDIF]";

    expect(($this->render)($content, $this->member))->toBe('Thanks for being a VIP')
        ->and(($this->render)($content, $this->outsider))->toBe('Join the VIP list');
});

it('accepts a uid, a name, quotes or none, in any case', function (): void {
    foreach ([
        "[IF IN SEGMENT 'VIPs']yes[ELSE]no[ENDIF]",
        '[IF IN SEGMENT "VIPs"]yes[ELSE]no[ENDIF]',
        '[IF in segment VIPs]yes[ELSE]no[ENDIF]',
        "[IF IN SEGMENT '".$this->segment->uid."']yes[ELSE]no[ENDIF]",
    ] as $content) {
        expect(($this->render)($content, $this->member))->toBe('yes', $content);
    }
});

// D-90, applied verbatim: an unknown segment matches NOTHING in BOTH
// directions, so neither `IN` nor `NOT IN` can widen an audience by accident.
it('matches nothing in both directions for an unknown segment', function (): void {
    expect(($this->render)("[IF IN SEGMENT 'Nope']yes[ELSE]no[ENDIF]", $this->member))->toBe('no')
        ->and(($this->render)("[IF NOT IN SEGMENT 'Nope']yes[ELSE]no[ENDIF]", $this->member))->toBe('no')
        // With no ELSE branch at all, the block simply disappears.
        ->and(($this->render)("before[IF IN SEGMENT 'Nope']yes[ENDIF]after", $this->member))->toBe('beforeafter');
});

it('never resolves another tenants segment', function (): void {
    $other = Customer::factory()->create();
    $otherList = EmailList::factory()->for($other, 'customer')->create();
    $foreign = ListSegment::factory()->for($otherList, 'list')->create([
        'name' => 'Foreign VIPs',
        'customer_id' => $other->id,
    ]);

    // By name and by uid, and in both directions — nothing matches.
    foreach (['Foreign VIPs', $foreign->uid] as $reference) {
        expect(($this->render)("[IF IN SEGMENT '{$reference}']yes[ELSE]no[ENDIF]", $this->member))->toBe('no')
            ->and(($this->render)("[IF NOT IN SEGMENT '{$reference}']yes[ELSE]no[ENDIF]", $this->member))->toBe('no');
    }

    // …and the resolver itself refuses the cross-tenant lookup.
    expect(app(SegmentContent::class)->resolve((int) $this->customer->id, 'Foreign VIPs'))->toBeNull()
        ->and(app(SegmentContent::class)->resolve((int) $other->id, 'Foreign VIPs'))->toBe((int) $foreign->id);
});

it('nests with the existing tag conditions in both orders', function (): void {
    $inner = "[IF IN SEGMENT 'VIPs'][IF CITY = 'Casablanca']both[ELSE]segment only[ENDIF][ELSE]neither[ENDIF]";

    expect(($this->render)($inner, $this->member))->toBe('both')
        ->and(($this->render)($inner, $this->outsider))->toBe('neither');

    $outer = "[IF CITY = 'Rabat'][IF NOT IN SEGMENT 'VIPs']rabat non-vip[ENDIF][ELSE]other[ENDIF]";

    expect(($this->render)($outer, $this->outsider))->toBe('rabat non-vip')
        ->and(($this->render)($outer, $this->member))->toBe('other');
});

it('keeps the D-62 order: the untaken branch never reaches tag substitution', function (): void {
    // {NOT_A_TAG} would be reported by the broken-tag scanner if it survived.
    $content = "[IF IN SEGMENT 'VIPs']Hi {EMAIL}[ELSE]{NOT_A_TAG}[ENDIF]";

    expect(($this->render)($content, $this->member))->toBe('Hi ana@fixture.test')
        ->and(($this->render)($content, $this->member))->not->toContain('NOT_A_TAG');
});

/* ====================================================================== *
 *  Cost
 * ====================================================================== */

it('costs the same however often the block repeats in one body', function (): void {
    // Warm the process-lifetime name→id memo so we measure MEMBERSHIP only.
    ($this->render)("[IF IN SEGMENT 'VIPs']x[ENDIF]", $this->member);

    $values = app(MergeTags::class)->values($this->campaign, $this->list, $this->member);

    $cost = function (int $repeats) use ($values): int {
        $content = str_repeat("[IF IN SEGMENT 'VIPs']vip[ELSE]not[ENDIF]", $repeats);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // A FRESH ConditionalContent each time — exactly what MergeTags does
        // per render, so the memo cannot leak between measurements.
        $rendered = app(ConditionalContent::class)->render($content, $values);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($rendered)->toBe(str_repeat('vip', $repeats));

        return count($queries);
    };

    $one = $cost(1);
    $twenty = $cost(20);

    // The invariant that matters for a batch send: membership is resolved once
    // per (segment, recipient), not once per occurrence.
    expect($twenty)->toBe($one)
        // …and one occurrence is a small constant, not a table scan.
        ->and($one)->toBeLessThanOrEqual(5);
});

/* ====================================================================== *
 *  Surfaces: the send path, the preview, the builder affordance
 * ====================================================================== */

it('personalises the real send path per recipient', function (): void {
    $this->campaign->update([
        'content_html' => "<p>[IF IN SEGMENT 'VIPs']VIP OFFER[ELSE]PUBLIC OFFER[ENDIF]</p>"
            .'<a href="{UNSUBSCRIBE_URL}">bye</a>',
    ]);

    $factory = app(CampaignEmailFactory::class);

    expect($factory->make($this->campaign->fresh(), $this->member)->html)->toContain('VIP OFFER')
        ->and($factory->make($this->campaign->fresh(), $this->member)->html)->not->toContain('PUBLIC OFFER')
        ->and($factory->make($this->campaign->fresh(), $this->outsider)->html)->toContain('PUBLIC OFFER')
        ->and($factory->make($this->campaign->fresh(), $this->outsider)->html)->not->toContain('VIP OFFER');
});

it('shows the matched branch in a preview and says that it did', function (): void {
    $this->campaign->update([
        'content_html' => "<p>[IF IN SEGMENT 'VIPs']VIP OFFER[ELSE]PUBLIC OFFER[ENDIF]</p>",
    ]);

    $renderer = app(CampaignPreviewRenderer::class);
    $html = $renderer->render($this->campaign->fresh());

    // A preview has no recipient — the personalised branch is what the
    // customer is trying to look at.
    expect($html)->toContain('VIP OFFER')
        ->and($html)->not->toContain('PUBLIC OFFER')
        ->and($html)->not->toContain('[IF')
        ->and($renderer->segmentReferences($this->campaign->fresh()))->toBe(['VIPs']);

    // …and the preview view says so rather than letting it read as everybody's
    // copy.
    $view = view('filament.customer.campaigns.preview', [
        'html' => $html,
        'segmentReferences' => ['VIPs'],
    ])->render();

    expect($view)->toContain(e(__('campaigns.smart_content.preview_notice', ['segments' => 'VIPs'])));

    // An unknown segment still matches nothing, even in a preview.
    $this->campaign->update(['content_html' => "[IF IN SEGMENT 'Nope']yes[ELSE]no[ENDIF]"]);

    expect($renderer->render($this->campaign->fresh()))->toBe('no');
});

it('composes valid syntax from the builder affordance', function (): void {
    $block = CampaignResource::smartContentBlock((string) $this->segment->uid, false, '<p>VIP</p>', '<p>Public</p>');

    expect($block)->toContain("[IF IN SEGMENT '".$this->segment->uid."']")
        ->and($block)->toContain('[ELSE]')
        ->and($block)->toContain('[ENDIF]')
        // What it writes is what the engine reads.
        ->and(trim(($this->render)($block, $this->member)))->toBe('<p>VIP</p>')
        ->and(trim(($this->render)($block, $this->outsider)))->toBe('<p>Public</p>');

    $negated = CampaignResource::smartContentBlock((string) $this->segment->uid, true, '<p>Join</p>', '');

    expect($negated)->toContain("[IF NOT IN SEGMENT '")
        ->and($negated)->not->toContain('[ELSE]')
        ->and(trim(($this->render)($negated, $this->outsider)))->toBe('<p>Join</p>')
        ->and(trim(($this->render)($negated, $this->member)))->toBe('');

    // The picker only offers this tenant's segments, for this list.
    $options = CampaignResource::smartContentSegments($this->list->id);

    expect($options)->toBe([$this->segment->id => 'VIPs']);
});

it('leaves ordinary conditional content untouched', function (): void {
    // Regression guard for F-2.3.3-008: the new predicate must not shadow the
    // tag grammar, and content with no [IF at all must not pay for any of it.
    expect(($this->render)("[IF CITY = 'Casablanca']yes[ELSE]no[ENDIF]", $this->member))->toBe('yes')
        ->and(($this->render)('<p>Plain body</p>', $this->member))->toBe('<p>Plain body</p>')
        ->and(app(SegmentContent::class)->handles("CITY = 'Casablanca'"))->toBeFalse()
        ->and(app(SegmentContent::class)->handles("IN SEGMENT 'VIPs'"))->toBeTrue()
        ->and(app(SegmentContent::class)->handles("NOT IN SEGMENT 'VIPs'"))->toBeTrue();
});
