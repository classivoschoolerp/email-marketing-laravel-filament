<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\CurrentCustomer;

/**
 * F-2.2.10-011 — nested condition groups. Conditions sharing a `group_index`
 * are combined with that group's own operator; the groups are then combined
 * with the segment's match type. ALL + per-group ANY = the spec's AND of ORs.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->builder = app(SegmentQueryBuilder::class);

    $this->city = ListField::factory()->for($this->list, 'list')->create(['tag' => 'CITY']);
    $service = app(FieldValueService::class);

    // casa/rabat/paris in each of confirmed + unsubscribed.
    foreach ([
        ['casa-ok@x.example', 'Casablanca', false],
        ['rabat-ok@x.example', 'Rabat', false],
        ['paris-ok@x.example', 'Paris', false],
        ['casa-out@x.example', 'Casablanca', true],
        ['rabat-out@x.example', 'Rabat', true],
        ['paris-out@x.example', 'Paris', true],
    ] as [$email, $city, $unsubscribed]) {
        $factory = Subscriber::factory()->for($this->list, 'list');
        $subscriber = ($unsubscribed ? $factory->unsubscribed() : $factory)->create(['email' => $email]);
        $service->set($subscriber, $this->city, $city);
    }

    $this->emails = fn (string $match, array $conditions): array => $this->builder
        ->build($this->list, $match, $conditions)
        ->orderBy('email')
        ->pluck('email')
        ->all();
});

it('evaluates flat segments exactly as before when no groups are used (F-2.2.10-011 regression)', function (): void {
    $emails = $this->emails;

    $flat = [
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Casablanca'],
        ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed'],
    ];

    // No group keys at all — the pre-WP-42 shape.
    expect($emails('all', $flat))->toBe(['casa-ok@x.example'])
        ->and($emails('any', $flat))
        ->toBe(['casa-ok@x.example', 'casa-out@x.example', 'paris-ok@x.example', 'rabat-ok@x.example']);

    // The stored default (group_index 0, no group_match) must be identical.
    $grouped = array_map(fn (array $c): array => $c + ['group_index' => 0, 'group_match' => null], $flat);

    expect($emails('all', $grouped))->toBe($emails('all', $flat))
        ->and($emails('any', $grouped))->toBe($emails('any', $flat));
});

it('combines groups as AND of ORs (F-2.2.10-011)', function (): void {
    $emails = $this->emails;

    // (city = Casablanca OR city = Rabat) AND status = confirmed
    $conditions = [
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Casablanca', 'group_index' => 0, 'group_match' => 'any'],
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Rabat', 'group_index' => 0, 'group_match' => 'any'],
        ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed', 'group_index' => 1, 'group_match' => 'all'],
    ];

    expect($emails('all', $conditions))->toBe(['casa-ok@x.example', 'rabat-ok@x.example']);

    // Flattening the same three conditions cannot express this: ALL matches
    // nobody (no one lives in two cities) and ANY drags in the unsubscribed
    // Casablanca/Rabat members plus every other confirmed subscriber.
    $flat = array_map(function (array $condition): array {
        unset($condition['group_index'], $condition['group_match']);

        return $condition;
    }, $conditions);

    expect($emails('all', $flat))->toBe([])
        ->and($emails('any', $flat))->toBe([
            'casa-ok@x.example',
            'casa-out@x.example',
            'paris-ok@x.example',
            'rabat-ok@x.example',
            'rabat-out@x.example',
        ]);
});

it('combines groups as OR of ANDs when the segment matches ANY (F-2.2.10-011)', function (): void {
    $emails = $this->emails;

    // (city = Casablanca AND confirmed) OR (city = Paris AND unsubscribed)
    $conditions = [
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Casablanca', 'group_index' => 0, 'group_match' => 'all'],
        ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed', 'group_index' => 0, 'group_match' => 'all'],
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Paris', 'group_index' => 1, 'group_match' => 'all'],
        ['type' => 'status', 'operator' => 'is', 'value' => 'unsubscribed', 'group_index' => 1, 'group_match' => 'all'],
    ];

    expect($emails('any', $conditions))->toBe(['casa-ok@x.example', 'paris-out@x.example']);
});

it('stores and re-evaluates a saved grouped segment (F-2.2.10-011)', function (): void {
    $segment = ListSegment::factory()->for($this->list, 'list')->create(['match_type' => 'all']);

    foreach ([
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Casablanca', 'group_index' => 0, 'group_match' => 'any'],
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Rabat', 'group_index' => 0, 'group_match' => 'any'],
        ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed', 'group_index' => 1, 'group_match' => 'all'],
    ] as $index => $condition) {
        $segment->conditions()->create($condition + [
            'customer_id' => $this->customer->id,
            'sort_order' => $index,
        ]);
    }

    expect($segment->conditions()->where('group_index', 0)->count())->toBe(2)
        ->and($this->builder->subscribers($segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['casa-ok@x.example', 'rabat-ok@x.example'])
        ->and($this->builder->count($segment))->toBe(2);
});

it('falls back to the segment match type for a group with no operator (F-2.2.10-011)', function (): void {
    $emails = $this->emails;

    // group 0 says ANY, group 1 declares nothing → inherits the segment's ALL.
    $conditions = [
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Casablanca', 'group_index' => 0, 'group_match' => 'any'],
        ['type' => 'field', 'list_field_id' => $this->city->id, 'operator' => 'is', 'value' => 'Paris', 'group_index' => 0, 'group_match' => 'any'],
        ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed', 'group_index' => 1],
        ['type' => 'field', 'operator' => 'contains', 'value' => 'ok@', 'group_index' => 1],
    ];

    expect($emails('all', $conditions))->toBe(['casa-ok@x.example', 'paris-ok@x.example']);
});
