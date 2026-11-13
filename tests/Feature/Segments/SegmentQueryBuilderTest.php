<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Models\Campaign;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Lists\FieldValueService;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->builder = app(SegmentQueryBuilder::class);
});

function makeSegment(string $match, array $conditions): ListSegment
{
    $segment = ListSegment::factory()
        ->for(test()->list, 'list')
        ->create(['match_type' => $match]);

    foreach ($conditions as $index => $condition) {
        $segment->conditions()->create($condition + [
            'customer_id' => test()->customer->id,
            'sort_order' => $index,
        ]);
    }

    return $segment;
}

it('applies every field operator against custom field values (F-2.2.10-002)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'CITY']);
    $service = app(FieldValueService::class);

    $casa = Subscriber::factory()->for($this->list, 'list')->create();
    $rabat = Subscriber::factory()->for($this->list, 'list')->create();
    $empty = Subscriber::factory()->for($this->list, 'list')->create();

    $service->set($casa, $field, 'Casablanca');
    $service->set($rabat, $field, 'Rabat');

    $count = fn (string $operator, ?string $value = null, ?string $value2 = null): int => $this->builder->countFor($this->list, 'all', [[
        'type' => 'field',
        'list_field_id' => $field->id,
        'operator' => $operator,
        'value' => $value,
        'value2' => $value2,
    ]]);

    expect($count('is', 'Casablanca'))->toBe(1)
        ->and($count('is_not', 'Casablanca'))->toBe(2)
        ->and($count('contains', 'blanc'))->toBe(1)
        ->and($count('not_contains', 'blanc'))->toBe(2)
        ->and($count('starts_with', 'Casa'))->toBe(1)
        ->and($count('ends_with', 'bat'))->toBe(1)
        ->and($count('is_empty'))->toBe(1)
        ->and($count('not_empty'))->toBe(2);
});

it('compares numeric fields numerically for gt/lt/between', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Number)->create(['tag' => 'AGE']);
    $service = app(FieldValueService::class);

    foreach ([9, 25, 40] as $age) {
        $subscriber = Subscriber::factory()->for($this->list, 'list')->create();
        $service->set($subscriber, $field, $age);
    }

    $count = fn (string $operator, ?string $value = null, ?string $value2 = null): int => $this->builder->countFor($this->list, 'all', [[
        'type' => 'field',
        'list_field_id' => $field->id,
        'operator' => $operator,
        'value' => $value,
        'value2' => $value2,
    ]]);

    // String comparison would put "9" above "25"; numeric casting must not.
    expect($count('greater_than', '10'))->toBe(2)
        ->and($count('less_than', '10'))->toBe(1)
        ->and($count('between', '20', '30'))->toBe(1);
});

it('matches the email column when no custom field is chosen', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'ada@corp.example']);
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'bob@other.example']);

    expect($this->builder->countFor($this->list, 'all', [[
        'type' => 'field',
        'operator' => 'contains',
        'value' => 'corp',
    ]]))->toBe(1);
});

it('combines conditions with ALL versus ANY (F-2.2.10-001)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'ada@corp.example']);
    Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create(['email' => 'eve@corp.example']);
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'bob@other.example']);

    $conditions = [
        ['type' => 'field', 'operator' => 'contains', 'value' => 'corp'],
        ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed'],
    ];

    expect($this->builder->countFor($this->list, 'all', $conditions))->toBe(1)
        ->and($this->builder->countFor($this->list, 'any', $conditions))->toBe(3);
});

it('filters by subscribe date and last activity (F-2.2.10-004)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'old@x.example',
        'subscribed_at' => now()->subDays(30),
        'last_open_at' => now()->subDays(20),
    ]);
    Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'fresh@x.example',
        'subscribed_at' => now(),
        'last_click_at' => now(),
    ]);
    Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'silent@x.example',
        'subscribed_at' => now()->subDays(10),
    ]);

    $subscribed = fn (string $operator, ?string $value = null, ?string $value2 = null): int => $this->builder->countFor($this->list, 'all', [[
        'type' => 'date',
        'field_key' => 'subscribed',
        'operator' => $operator,
        'value' => $value,
        'value2' => $value2,
    ]]);

    expect($subscribed('before', now()->subDays(15)->toDateString()))->toBe(1)
        ->and($subscribed('after', now()->subDays(15)->toDateString()))->toBe(2)
        ->and($subscribed('between', now()->subDays(15)->toDateString(), now()->subDays(5)->toDateString()))->toBe(1);

    $activity = fn (string $operator, ?string $value = null, ?string $value2 = null): int => $this->builder->countFor($this->list, 'all', [[
        'type' => 'date',
        'field_key' => 'last_activity',
        'operator' => $operator,
        'value' => $value,
        'value2' => $value2,
    ]]);

    // silent@ has no activity at all and must never match.
    expect($activity('after', now()->subDays(5)->toDateString()))->toBe(1)
        ->and($activity('before', now()->subDays(5)->toDateString()))->toBe(1);
});

it('filters by status (F-2.2.10-005)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->count(2)->create();
    Subscriber::factory()->for($this->list, 'list')->bounced()->create();

    expect($this->builder->countFor($this->list, 'all', [
        ['type' => 'status', 'operator' => 'is', 'value' => 'bounced'],
    ]))->toBe(1)
        ->and($this->builder->countFor($this->list, 'all', [
            ['type' => 'status', 'operator' => 'is_not', 'value' => 'bounced'],
        ]))->toBe(2);
});

describe('campaign conditions (F-2.2.10-003)', function (): void {
    beforeEach(function (): void {
        // Four subscribers, referenced by their tracking uid.
        $this->s1 = Subscriber::factory()->for($this->list, 'list')->create(['email' => 's1@x.example']);
        $this->s2 = Subscriber::factory()->for($this->list, 'list')->create(['email' => 's2@x.example']);
        $this->s3 = Subscriber::factory()->for($this->list, 'list')->create(['email' => 's3@x.example']);
        $this->s4 = Subscriber::factory()->for($this->list, 'list')->create(['email' => 's4@x.example']);

        $this->campaignA = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);
        $this->campaignB = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);
        // Another tenant entirely — its tracking rows must never count.
        $this->foreignCampaign = Campaign::factory()->create();

        $this->urlX = CampaignTrackUrl::create([
            'campaign_id' => $this->campaignA->id,
            'campaign_uid' => $this->campaignA->uid,
            'url' => 'https://example.test/x',
            'hash' => str_repeat('x', 64),
        ]);
        $this->urlY = CampaignTrackUrl::create([
            'campaign_id' => $this->campaignA->id,
            'campaign_uid' => $this->campaignA->uid,
            'url' => 'https://example.test/y',
            'hash' => str_repeat('y', 64),
        ]);
        $this->urlZ = CampaignTrackUrl::create([
            'campaign_id' => $this->campaignB->id,
            'campaign_uid' => $this->campaignB->uid,
            'url' => 'https://example.test/z',
            'hash' => str_repeat('z', 64),
        ]);

        $open = fn (Subscriber $s, Campaign $c, $at) => CampaignTrackOpen::create([
            'campaign_id' => $c->id,
            'campaign_uid' => $c->uid,
            'subscriber_uid' => $s->uid,
            'created_at' => $at,
        ]);

        $open($this->s1, $this->campaignA, now()->subDay());
        $open($this->s2, $this->campaignA, now()->subDays(60));
        $open($this->s3, $this->campaignB, now()->subDay());
        $open($this->s4, $this->foreignCampaign, now()->subDay());

        $click = fn (Subscriber $s, Campaign $c, $url, $at) => CampaignTrackClick::create([
            'campaign_id' => $c->id,
            'campaign_uid' => $c->uid,
            'subscriber_uid' => $s->uid,
            'url_id' => $url->id,
            'created_at' => $at,
        ]);

        $click($this->s1, $this->campaignA, $this->urlX, now()->subDay());
        $click($this->s2, $this->campaignA, $this->urlY, now()->subDays(60));
        $click($this->s4, $this->campaignB, $this->urlZ, now()->subDay());

        $this->campaignCount = fn (string $operator, array $settings = []): int => $this->builder->countFor(
            $this->list,
            'all',
            [['type' => 'campaign', 'operator' => $operator, 'settings' => $settings]],
        );
    });

    it('matches opens and non-opens for a specific campaign', function (): void {
        $count = $this->campaignCount;
        $settings = ['campaign_id' => $this->campaignA->id];

        // s1 + s2 opened campaign A; s3 opened B, s4 opened only the foreign one.
        expect($count('opened', $settings))->toBe(2)
            ->and($count('not_opened', $settings))->toBe(2);
    });

    it('matches opens across ANY campaign of the tenant only', function (): void {
        $count = $this->campaignCount;

        // s1 (A) + s2 (A) + s3 (B). s4's open belongs to another tenant.
        expect($count('opened'))->toBe(3)
            ->and($count('not_opened'))->toBe(1);
    });

    it('honours the "any campaign in the last N days" window', function (): void {
        $count = $this->campaignCount;

        // Only s1 and s3 opened within 30 days; s2's open is 60 days old.
        expect($count('opened', ['within_days' => 30]))->toBe(2)
            ->and($count('not_opened', ['within_days' => 30]))->toBe(2)
            // A window on one specific campaign narrows A's two openers to one.
            ->and($count('opened', ['campaign_id' => $this->campaignA->id, 'within_days' => 30]))->toBe(1)
            // A window wide enough to cover every event returns the unwindowed set.
            ->and($count('opened', ['within_days' => 365]))->toBe(3);
    });

    it('matches clicks, non-clicks and one specific link', function (): void {
        $count = $this->campaignCount;

        expect($count('clicked_any', ['campaign_id' => $this->campaignA->id]))->toBe(2)
            ->and($count('not_clicked', ['campaign_id' => $this->campaignA->id]))->toBe(2)
            // Tenant-wide: s1 + s2 (A) and s4 (B).
            ->and($count('clicked_any'))->toBe(3)
            ->and($count('not_clicked'))->toBe(1)
            ->and($count('clicked_link', ['campaign_id' => $this->campaignA->id, 'link_id' => $this->urlX->id]))->toBe(1)
            ->and($count('clicked_link', ['campaign_id' => $this->campaignA->id, 'link_id' => $this->urlY->id]))->toBe(1)
            ->and($count('clicked_link', ['link_id' => $this->urlZ->id]))->toBe(1)
            // Clicks within the last 30 days: s1 and s4 only.
            ->and($count('clicked_any', ['within_days' => 30]))->toBe(2);
    });

    it('matches nothing when the campaign or link is unknown, cross-tenant or missing', function (): void {
        $count = $this->campaignCount;

        expect($count('opened', ['campaign_id' => $this->foreignCampaign->id]))->toBe(0)
            ->and($count('not_opened', ['campaign_id' => $this->foreignCampaign->id]))->toBe(0)
            ->and($count('opened', ['campaign_id' => 999999]))->toBe(0)
            // Link belongs to campaign B, condition pins campaign A.
            ->and($count('clicked_link', ['campaign_id' => $this->campaignA->id, 'link_id' => $this->urlZ->id]))->toBe(0)
            ->and($count('clicked_link', []))->toBe(0);
    });

    it('evaluates a saved campaign segment through its stored settings', function (): void {
        $segment = makeSegment('all', [[
            'type' => 'campaign',
            'operator' => 'clicked_link',
            'settings' => ['campaign_id' => $this->campaignA->id, 'link_id' => $this->urlX->id],
        ]]);

        expect($segment->conditions()->count())->toBe(1)
            ->and($this->builder->count($segment))->toBe(1)
            ->and($this->builder->subscribers($segment)->pluck('email')->all())->toBe(['s1@x.example']);
    });

    // F-2.2.10-014 "use segment as campaign target": a campaign pointed at a
    // campaign-condition segment resolves to exactly those subscribers.
    it('targets a campaign at a segment built from campaign conditions', function (): void {
        $segment = makeSegment('all', [[
            'type' => 'campaign',
            'operator' => 'opened',
            'settings' => ['campaign_id' => $this->campaignA->id],
        ]]);

        $followUp = Campaign::factory()->for($this->customer)->create([
            'email_list_id' => $this->list->id,
            'list_segment_id' => $segment->id,
        ]);

        // s1 + s2 opened campaign A and are both confirmed.
        expect(app(CampaignRecipients::class)->count($followUp))->toBe(2);

        // Untargeted, the same campaign would reach all four list members.
        $followUp->list_segment_id = null;
        $followUp->save();

        expect(app(CampaignRecipients::class)->count($followUp))->toBe(4);
    });

    it('offers tenant-scoped campaign and link pickers', function (): void {
        $options = $this->builder->campaignOptions($this->list);

        expect($options)->toHaveKey($this->campaignA->id)
            ->and($options)->toHaveKey($this->campaignB->id)
            ->and($options)->not->toHaveKey($this->foreignCampaign->id)
            ->and($this->builder->linkOptions($this->list, $this->campaignA->id))->toBe([
                $this->urlX->id => 'https://example.test/x',
                $this->urlY->id => 'https://example.test/y',
            ])
            ->and($this->builder->linkOptions($this->list, $this->foreignCampaign->id))->toBe([])
            ->and($this->builder->linkOptions($this->list, null))->toBe([]);
    });
});

it('evaluates segments dynamically at read time (F-2.2.10-012)', function (): void {
    $segment = makeSegment('all', [[
        'type' => 'status',
        'operator' => 'is',
        'value' => 'confirmed',
    ]]);

    expect($this->builder->count($segment))->toBe(0);

    Subscriber::factory()->for($this->list, 'list')->count(2)->create();

    // No recomputation or saving needed — the same segment now matches more.
    expect($this->builder->count($segment))->toBe(2);

    $query = $this->builder->subscribers($segment);

    expect($query)->toBeInstanceOf(Builder::class)
        ->and($query->pluck('email'))->toHaveCount(2);
});

it('never leaks subscribers from other lists or tenants', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create();

    $otherList = EmailList::factory()->for($this->customer)->create();
    Subscriber::factory()->for($otherList, 'list')->create();

    $foreign = Subscriber::factory()->create(); // other tenant entirely

    $segment = makeSegment('all', [[
        'type' => 'status', 'operator' => 'is', 'value' => 'confirmed',
    ]]);

    $emails = $this->builder->subscribers($segment)->pluck('email');

    expect($emails)->toHaveCount(1)
        ->and($emails)->not->toContain($foreign->email);
});
