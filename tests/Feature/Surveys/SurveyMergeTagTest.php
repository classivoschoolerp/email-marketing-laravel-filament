<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Services\Content\MergeTags;
use App\Services\Surveys\SurveyLinkResolver;
use App\Support\Tenancy\CurrentCustomer;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.8-008 — link a survey from a campaign via a merge tag.
 *
 * `{SURVEY_URL:<survey_uid>}` renders the public start URL and appends the
 * recipient's subscriber uid, so the response arrives identified (F-1.9-002)
 * instead of anonymous.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'NPS',
    ]);
    SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'tag' => 'ANSWER',
        'sort_order' => 0,
    ]);

    $this->subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'reader@example.test',
    ]);
});

it('exposes a copyable merge tag per survey (F-2.8-008)', function (): void {
    expect(app(SurveyLinkResolver::class)->tagFor($this->survey))
        ->toBe('{SURVEY_URL:'.$this->survey->uid.'}');

    // The canonical registry knows the tag, so the broken-tag scanner does
    // not flag a campaign that uses it.
    expect(MergeTags::TAGS)->toContain('SURVEY_URL');
});

it('renders an identified survey link inside campaign content (F-2.8-008)', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $tags = app(MergeTags::class);

    $html = $tags->renderFor(
        '<p>Hi {FNAME|there}, <a href="{SURVEY_URL:'.$this->survey->uid.'}">tell us</a></p>',
        $campaign,
        $this->list,
        $this->subscriber,
    );

    $expected = route('surveys.public.start', [
        'uid' => $this->survey->uid,
        's' => $this->subscriber->uid,
    ]);

    expect($html)->toContain($expected);

    // …and the link actually identifies the recipient on the public page.
    auth('customer')->logout();
    $this->get($expected)->assertOk()->assertSee('reader@example.test');
});

it('renders an anonymous link when there is no recipient', function (): void {
    $html = app(MergeTags::class)->renderFor(
        'Take it: {SURVEY_URL:'.$this->survey->uid.'}',
        null,
        $this->list,
        null,
    );

    expect($html)->toContain(route('surveys.public.start', ['uid' => $this->survey->uid]))
        ->and($html)->not->toContain('?s=');
});

it('renders empty for an unknown or cross-tenant survey uid, never a broken link', function (): void {
    $other = Customer::factory()->create();

    $foreignSurvey = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Survey => Survey::factory()->create(['customer_id' => $other->id]),
    );

    $resolver = app(SurveyLinkResolver::class);

    expect($resolver->url('not-a-real-uid', $this->subscriber))->toBe('')
        // Cross-tenant: my recipient may never be sent to their survey.
        ->and($resolver->url((string) $foreignSurvey->uid, $this->subscriber))->toBe('');

    $html = app(MergeTags::class)->renderFor(
        'Go: [{SURVEY_URL:'.$foreignSurvey->uid.'}]',
        null,
        $this->list,
        $this->subscriber,
    );

    expect($html)->toBe('Go: []');
});

it('leaves content without the tag completely untouched', function (): void {
    $content = 'Hello {FNAME|friend}, nothing to see here.';

    expect(app(MergeTags::class)->render($content, ['FNAME' => 'Ada']))
        ->toBe('Hello Ada, nothing to see here.');
});
