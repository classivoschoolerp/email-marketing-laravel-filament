<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Form;
use App\Models\FormStatDaily;
use App\Services\Forms\FormAnalytics;
use App\Services\Forms\FormRenderer;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;

/*
 * F-2.7-012 — form analytics: views, submits, conversion rate, by source.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-13 10:00:00');

    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    $this->analytics = app(FormAnalytics::class);
});

afterEach(fn () => Carbon::setTestNow());

it('counts views and submissions and derives the conversion rate (F-2.7-012)', function (): void {
    foreach (range(1, 4) as $ignored) {
        $this->analytics->recordView($this->form);
    }

    $this->analytics->recordSubmission($this->form);

    expect($this->analytics->summary($this->form))->toBe([
        'views' => 4,
        'submissions' => 1,
        'conversion_rate' => 25.0,
    ]);

    // A form with no views has a 0% rate, never a division by zero.
    $fresh = Form::factory()->create(['customer_id' => $this->customer->id, 'list_id' => $this->list->id]);
    expect($this->analytics->summary($fresh)['conversion_rate'])->toBe(0.0);
});

it('breaks views and submissions down by source (F-2.7-012)', function (): void {
    $this->analytics->recordView($this->form, 'https://news.example.com/article?utm_source=x');
    $this->analytics->recordView($this->form, 'https://news.example.com/other');
    $this->analytics->recordSubmission($this->form, 'https://news.example.com/article');

    $this->analytics->recordView($this->form, null);

    $rows = collect($this->analytics->bySource($this->form))->keyBy('source');

    expect($rows->keys()->sort()->values()->all())->toBe(['direct', 'news.example.com'])
        ->and($rows['news.example.com']['views'])->toBe(2)
        ->and($rows['news.example.com']['submissions'])->toBe(1)
        ->and($rows['news.example.com']['conversion_rate'])->toBe(50.0)
        ->and($rows['direct']['views'])->toBe(1)
        ->and($rows['direct']['submissions'])->toBe(0);
});

it('normalises hostile source strings before storing them', function (): void {
    expect(FormAnalytics::normalizeSource('  '))->toBe('direct')
        ->and(FormAnalytics::normalizeSource('https://Example.COM/path'))->toBe('example.com')
        ->and(FormAnalytics::normalizeSource("nasty'; DROP TABLE forms;--"))->toBe('nasty---drop-table-forms')
        ->and(mb_strlen(FormAnalytics::normalizeSource(str_repeat('a', 500))))->toBe(64);
});

it('records a view from the public impression beacon and the hosted page', function (): void {
    $this->post(route('forms.public.impression', ['uid' => $this->form->uid]), [
        'source' => 'https://partner.example.org/landing',
    ])->assertNoContent();

    $this->get(route('forms.public.show', ['uid' => $this->form->uid]))->assertOk();

    expect($this->analytics->summary($this->form)['views'])->toBe(2);

    $sources = collect($this->analytics->bySource($this->form))->pluck('source')->sort()->values()->all();
    expect($sources)->toBe(['direct', 'partner.example.org']);
});

it('counts a real public submission as a conversion on its own source', function (): void {
    $this->post(route('forms.public.impression', ['uid' => $this->form->uid]), [
        'source' => 'https://blog.example.net/post',
    ])->assertNoContent();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'converted@example.test',
        FormRenderer::SOURCE_FIELD => 'https://blog.example.net/post',
    ])->assertOk();

    $row = collect($this->analytics->bySource($this->form))->firstWhere('source', 'blog.example.net');

    expect($row['views'])->toBe(1)
        ->and($row['submissions'])->toBe(1)
        ->and($row['conversion_rate'])->toBe(100.0);
});

it('returns a zero-filled daily series (F-2.7-012)', function (): void {
    FormStatDaily::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'form_id' => $this->form->id,
        'stat_date' => Carbon::today()->subDays(3)->toDateString(),
        'source' => 'direct',
        'views' => 9,
        'submissions' => 2,
    ]);

    $series = $this->analytics->daily($this->form, 7);

    expect($series)->toHaveCount(7)
        ->and($series[0]['date'])->toBe(Carbon::today()->subDays(6)->toDateString())
        ->and($series[6]['date'])->toBe(Carbon::today()->toDateString())
        ->and($series[3]['views'])->toBe(9)
        ->and($series[3]['submissions'])->toBe(2)
        ->and($series[6]['views'])->toBe(0);
});

it('keeps analytics inside the owning tenant', function (): void {
    $other = Customer::factory()->create();

    $foreignForm = app(CurrentCustomer::class)->runAs($other, function () use ($other): Form {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);

        return Form::factory()->create(['customer_id' => $other->id, 'list_id' => $list->id]);
    });

    $this->analytics->recordView($foreignForm);
    $this->analytics->recordView($this->form);

    expect($this->analytics->summary($this->form)['views'])->toBe(1);

    // The rollup rows are tenant-scoped like everything else.
    expect(FormStatDaily::query()->count())->toBe(1)
        ->and(FormStatDaily::withoutTenancy()->count())->toBe(2);
});
