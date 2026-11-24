<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\FormResource\Pages\ManageFormVariants;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormStatDaily;
use App\Models\FormSubmission;
use App\Models\FormVariant;
use App\Services\Campaigns\AbTest\SignificanceTest;
use App\Services\Forms\FormAbTest;
use App\Services\Forms\FormAnalytics;
use App\Services\Forms\FormRenderer;
use App\Services\Forms\FormVariantAssigner;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;
use Tests\Support\LandingTestHelpers;

/*
 * F-2.7-013 — A/B tested forms.
 *
 *   • sticky per-visitor assignment in a COOKIE (never a session — D-95);
 *   • an arm may override copy, styling and the FIELD SET / field order;
 *   • views and submissions are attributed per arm through the EXTENDED
 *     unique index, not a second conflicting one;
 *   • the read-out reuses the product's one two-proportion z-test.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-13 10:00:00');

    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'heading' => 'Join us',
        'submit_label' => 'Subscribe',
        'captcha_enabled' => false,
        'ab_enabled' => true,
    ]);

    $this->fname = FormField::factory()->create([
        'form_id' => $this->form->id, 'customer_id' => $this->customer->id,
        'tag' => 'FNAME', 'label' => 'First name', 'sort_order' => 0,
    ]);

    $this->phone = FormField::factory()->create([
        'form_id' => $this->form->id, 'customer_id' => $this->customer->id,
        'tag' => 'PHONE', 'label' => 'Phone', 'sort_order' => 1,
    ]);

    $this->control = FormVariant::factory()->control()->create([
        'form_id' => $this->form->id, 'customer_id' => $this->customer->id, 'name' => 'Control',
    ]);

    $this->challenger = FormVariant::factory()->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'name' => 'Short form',
        'heading' => 'One field, that is all',
        'submit_label' => 'Get the guide',
        'field_ids' => [$this->fname->id],
        'styles' => ['button_color' => '#abcdef'],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('serves an arm and remembers it in a first-party cookie, never a session (D-95)', function (): void {
    $assigner = app(FormVariantAssigner::class);
    $cookie = $assigner->cookieName($this->form);

    expect($cookie)->toBe(FormVariantAssigner::COOKIE_PREFIX.$this->form->uid);

    $response = $this->get(route('forms.public.show', ['uid' => $this->form->uid]))->assertOk();

    // The plaintext a browser would store — the response value is encrypted.
    $assigned = LandingTestHelpers::plain((string) $response->getCookie($cookie, false)?->getValue());

    expect($assigned)->toBeIn([$this->control->uid, $this->challenger->uid]);

    // Sticky: carrying the cookie back returns the same arm, ten times over.
    foreach (range(1, 10) as $ignored) {
        $again = $this->withCookie($cookie, $assigned)
            ->get(route('forms.public.show', ['uid' => $this->form->uid]))
            ->assertOk()
            ->getContent();

        $expected = $assigned === $this->challenger->uid ? 'One field, that is all' : 'Join us';
        expect($again)->toContain($expected);
    }

    // A foreign tenant bound as the current customer changes nothing: the
    // public path never reads a session, only the cookie and the uid (D-95).
    $other = Customer::factory()->create();

    app(CurrentCustomer::class)->runAs($other, function () use ($cookie, $assigned): void {
        $html = $this->withCookie($cookie, $assigned)
            ->get(route('forms.public.show', ['uid' => $this->form->uid]))
            ->assertOk()
            ->getContent();

        expect($html)->toContain('name="'.FormRenderer::VARIANT_FIELD.'" value="'.$assigned.'"');
    });
});

it('gives a declared winner all of the traffic', function (): void {
    $this->challenger->forceFill(['is_winner' => true, 'weight' => 1])->save();

    $assigner = app(FormVariantAssigner::class);

    foreach (range(1, 20) as $ignored) {
        expect($assigner->assign($this->form->refresh(), request())?->id)->toBe($this->challenger->id);
    }
});

it('draws by weight and skips disabled arms', function (): void {
    $this->control->forceFill(['enabled' => false])->save();

    $assigner = app(FormVariantAssigner::class);

    foreach (range(1, 20) as $ignored) {
        expect($assigner->assign($this->form->refresh(), request())?->id)->toBe($this->challenger->id);
    }

    // A cookie pointing at a now-disabled arm falls through to a fresh draw
    // rather than serving content the operator switched off.
    $request = request();
    $request->cookies->set($assigner->cookieName($this->form), (string) $this->control->uid);

    expect($assigner->assign($this->form->refresh(), $request)?->id)->toBe($this->challenger->id);
});

it('lets an arm override the copy, the styling and the FIELD SET and order', function (): void {
    $renderer = app(FormRenderer::class);

    $base = $renderer->body($this->form->refresh());
    expect($base)->toContain('Join us')
        ->and($base)->toContain('name="FNAME"')
        ->and($base)->toContain('name="PHONE"');

    $arm = $renderer->body($this->form, $this->challenger);

    expect($arm)->toContain('One field, that is all')
        ->and($arm)->toContain('Get the guide')
        ->and($arm)->toContain('name="FNAME"')
        // The phone input is not on this arm at all.
        ->and($arm)->not->toContain('name="PHONE"')
        // …and the arm carries its own identity into the POST body.
        ->and($arm)->toContain('name="'.FormRenderer::VARIANT_FIELD.'" value="'.$this->challenger->uid.'"');

    // Field ORDER, not just membership.
    $reordered = FormVariant::factory()->create([
        'form_id' => $this->form->id, 'customer_id' => $this->customer->id,
        'name' => 'Reordered', 'field_ids' => [$this->phone->id, $this->fname->id],
    ]);

    $html = $renderer->body($this->form, $reordered);

    expect((int) strpos($html, 'name="PHONE"'))->toBeLessThan((int) strpos($html, 'name="FNAME"'));

    // Styling overrides layer over the form's document.
    expect(App\Services\Forms\FormStyles::css($this->form, $this->challenger))->toContain('#abcdef');
});

it('holds a visitor only to the inputs their arm actually showed', function (): void {
    $this->phone->forceFill(['required' => true])->save();

    // The short arm never showed PHONE, so it cannot be required for it.
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'short@example.test',
        'FNAME' => 'Ada',
        FormRenderer::VARIANT_FIELD => $this->challenger->uid,
    ])->assertOk();

    // The control arm showed it, so it is.
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'long@example.test',
        'FNAME' => 'Ada',
        FormRenderer::VARIANT_FIELD => $this->control->uid,
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['PHONE']]);
});

it('attributes views and submissions per arm on ONE extended unique index', function (): void {
    $analytics = app(FormAnalytics::class);

    $analytics->recordView($this->form, 'https://a.example.com/', $this->control);
    $analytics->recordView($this->form, 'https://a.example.com/', $this->control);
    $analytics->recordView($this->form, 'https://a.example.com/', $this->challenger);

    // Same (form, day, source) three times, two arms → exactly two rows.
    expect(FormStatDaily::withoutTenancy()->where('form_id', $this->form->id)->count())->toBe(2);

    $rows = collect($analytics->byVariant($this->form))->keyBy('form_variant_id');

    expect((int) $rows[$this->control->id]['views'])->toBe(2)
        ->and((int) $rows[$this->challenger->id]['views'])->toBe(1);

    // A real submission stamps the arm on the submission row AND the counter.
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'armed@example.test',
        FormRenderer::SOURCE_FIELD => 'https://a.example.com/',
        FormRenderer::VARIANT_FIELD => $this->challenger->uid,
    ])->assertOk();

    $submission = FormSubmission::withoutTenancy()->where('email', 'armed@example.test')->firstOrFail();

    expect((int) $submission->form_variant_id)->toBe((int) $this->challenger->id);

    $rows = collect($analytics->byVariant($this->form))->keyBy('form_variant_id');
    expect((int) $rows[$this->challenger->id]['submissions'])->toBe(1)
        ->and((int) $rows[$this->control->id]['submissions'])->toBe(0)
        // …and the by-source totals still add up across arms.
        ->and($analytics->summary($this->form)['views'])->toBe(3);

    // Traffic with no arm keeps its own bucket rather than polluting one.
    $analytics->recordView($this->form, 'https://a.example.com/', null);

    expect(FormStatDaily::withoutTenancy()
        ->where('form_id', $this->form->id)
        ->where('form_variant_id', FormAnalytics::NO_VARIANT)
        ->value('views'))->toBe(1);
});

it('reports conversion and confidence per arm through the ONE z-test the product owns', function (): void {
    // 400 views / 40 conversions vs 400 views / 80 conversions.
    seedFormArm($this->form, $this->control, 400, 40);
    seedFormArm($this->form, $this->challenger, 400, 80);

    $report = app(FormAbTest::class)->report($this->form->refresh());
    $arms = collect($report['arms'])->keyBy('id');

    expect($report['enabled'])->toBeTrue()
        ->and($report['baseline_id'])->toBe((int) $this->control->id)
        ->and($report['winner_id'])->toBeNull()
        ->and($arms[$this->control->id]['is_baseline'])->toBeTrue()
        ->and($arms[$this->control->id]['conversion_rate'])->toBe(10.0)
        ->and($arms[$this->challenger->id]['conversion_rate'])->toBe(20.0)
        // The baseline is never compared with itself.
        ->and($arms[$this->control->id]['confidence'])->toBeNull()
        ->and($arms[$this->challenger->id]['significant'])->toBeTrue();

    // The number is exactly what SignificanceTest produces — no second maths.
    $expected = app(SignificanceTest::class)->compare(80, 400, 40, 400);

    expect($arms[$this->challenger->id]['confidence'])->toBe($expected->confidencePercent());
});

it('refuses to claim confidence below the shared minimum trial count', function (): void {
    seedFormArm($this->form, $this->control, SignificanceTest::MIN_TRIALS - 1, 1);
    seedFormArm($this->form, $this->challenger, SignificanceTest::MIN_TRIALS - 1, 9);

    $arms = collect(app(FormAbTest::class)->report($this->form->refresh())['arms'])->keyBy('id');

    expect($arms[$this->challenger->id]['confidence'])->toBeNull()
        ->and($arms[$this->challenger->id]['significant'])->toBeFalse();
});

it('surfaces the A/B read-out on the analytics page (F-2.7-013)', function (): void {
    seedFormArm($this->form, $this->control, 400, 40);
    seedFormArm($this->form, $this->challenger, 400, 80);

    $page = new App\Filament\Customer\Resources\FormResource\Pages\FormAnalyticsPage;
    $page->record = $this->form->refresh();

    $report = $page->abReport();

    expect($page->abMinTrials())->toBe(SignificanceTest::MIN_TRIALS)
        ->and(collect($report['arms'])->pluck('label')->all())->toBe(['Control', 'Short form']);

    // A form that never had an arm gets no A/B read-out at all — the section
    // is not rendered rather than rendered empty.
    $plain = Form::factory()->create(['customer_id' => $this->customer->id, 'list_id' => $this->list->id]);
    app(FormAnalytics::class)->recordView($plain);

    expect(app(FormAbTest::class)->report($plain)['arms'])->toBe([]);
});

it('keeps arms inside the owning tenant', function (): void {
    $other = Customer::factory()->create();

    $foreignForm = app(CurrentCustomer::class)->runAs($other, function () use ($other): Form {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);
        $form = Form::factory()->create(['customer_id' => $other->id, 'list_id' => $list->id, 'ab_enabled' => true]);
        FormVariant::factory()->create(['form_id' => $form->id, 'customer_id' => $other->id, 'name' => 'Theirs']);

        return $form;
    });

    expect(FormVariant::query()->count())->toBe(2)
        ->and(FormVariant::withoutTenancy()->count())->toBe(3);

    // A forged arm uid from another tenant's form is simply not an arm here.
    $foreignArm = FormVariant::withoutTenancy()->where('form_id', $foreignForm->id)->firstOrFail();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'forged@example.test',
        FormRenderer::VARIANT_FIELD => $foreignArm->uid,
    ])->assertOk();

    $submission = FormSubmission::withoutTenancy()->where('email', 'forged@example.test')->firstOrFail();

    expect($submission->form_variant_id)->toBeNull();
});

it('manages arms from the panel and never across tenants', function (): void {
    $customer = Fx::actingAsCustomer($this->customer);

    Livewire::test(ManageFormVariants::class, ['record' => $this->form->getKey()])
        ->assertOk()
        ->assertSee('Short form')
        ->callTableAction('create', data: [
            'name' => 'Long form',
            'weight' => 30,
            'heading' => 'Tell us everything',
            'enabled' => true,
        ])
        ->assertHasNoTableActionErrors();

    $created = FormVariant::query()->where('name', 'Long form')->firstOrFail();

    expect((int) $created->customer_id)->toBe((int) $customer->id)
        ->and((int) $created->weight)->toBe(30)
        ->and($created->uid)->not->toBeEmpty();

    // Another tenant's form is not reachable from this panel page.
    $other = Customer::factory()->create();

    [$foreign, $foreignArm] = app(CurrentCustomer::class)->runAs($other, function () use ($other): array {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);
        $form = Form::factory()->create(['customer_id' => $other->id, 'list_id' => $list->id]);

        return [$form, FormVariant::factory()->create([
            'form_id' => $form->id,
            'customer_id' => $other->id,
            'name' => 'Theirs',
        ])];
    });

    Fx::actingAsCustomer($customer);

    expect(fn () => Livewire::test(ManageFormVariants::class, ['record' => $foreign->getKey()]))
        ->toThrow(ModelNotFoundException::class);

    // …and the policy denies the row itself, scope or no scope.
    expect($customer->can('update', $foreignArm))->toBeFalse()
        ->and($customer->can('delete', $foreignArm))->toBeFalse();
});

/** Seed a whole test's worth of traffic onto one arm in one row. */
function seedFormArm(Form $form, FormVariant $variant, int $views, int $submissions): void
{
    FormStatDaily::withoutTenancy()->create([
        'customer_id' => $form->customer_id,
        'form_id' => $form->id,
        'form_variant_id' => $variant->id,
        'stat_date' => Carbon::today()->toDateString(),
        'source' => 'direct',
        'views' => $views,
        'submissions' => $submissions,
    ]);
}
