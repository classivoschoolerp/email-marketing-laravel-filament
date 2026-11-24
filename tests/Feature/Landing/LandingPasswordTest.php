<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\LandingPageResource\Pages\EditLandingPage;
use App\Models\EmailList;
use App\Models\Form;
use App\Models\LandingPage;
use App\Services\Landing\LandingBlocks;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §1.8 F-1.8-006 — password-protected landing pages (WP-46).
 *
 * The gate covers all three public surfaces (render, thank-you, submit), the
 * password is stored hashed, and rotating it revokes an existing unlock.
 */

beforeEach(function (): void {
    config()->set('app.url', 'http://localhost');

    $this->customer = Fx::actingAsCustomer();
    $this->page = LandingPage::factory()->published()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Investor deck',
        'slug' => 'investor-deck',
        'content_html' => '<section class="lp-hero"><h1 class="lp-h1">Secret numbers</h1></section>',
        'thank_you_html' => '<p>Secret thanks</p>',
        'access_password' => Hash::make('open-sesame'),
    ]);
});

it('renders the gate instead of the content, and leaks no page body (F-1.8-006)', function (): void {
    $this->get(route('landing.public.show', ['slug' => 'investor-deck']))
        ->assertOk()
        ->assertSee(__('landing.public.password_heading'))
        ->assertSee(__('landing.public.password_submit'))
        // The whole point: not one byte of the authored content ships.
        ->assertDontSee('Secret numbers')
        ->assertDontSee('<section class="lp-hero">', false)
        // A gated page must never be indexed.
        ->assertSee('<meta name="robots" content="noindex,nofollow">', false);
});

it('records no visit while the page is still locked', function (): void {
    $this->get(route('landing.public.show', ['slug' => 'investor-deck']))->assertOk();

    expect($this->page->visits()->withoutGlobalScopes()->count())->toBe(0);
});

it('unlocks with the right password and then serves the page', function (): void {
    $this->post(route('landing.public.unlock', ['slug' => 'investor-deck']), [
        'password' => 'open-sesame',
    ])->assertRedirect();

    $this->get(route('landing.public.show', ['slug' => 'investor-deck']))
        ->assertOk()
        ->assertSee('Secret numbers');
});

it('rejects the wrong password with 422 and stays locked', function (): void {
    $this->post(route('landing.public.unlock', ['slug' => 'investor-deck']), [
        'password' => 'guess',
    ])
        ->assertStatus(422)
        ->assertSee(__('landing.public.password_wrong'))
        ->assertDontSee('Secret numbers');

    $this->get(route('landing.public.show', ['slug' => 'investor-deck']))
        ->assertOk()
        ->assertDontSee('Secret numbers');
});

it('gates the thank-you page too', function (): void {
    $this->get(route('landing.public.thanks', ['slug' => 'investor-deck']))
        ->assertOk()
        ->assertDontSee('Secret thanks')
        ->assertSee(__('landing.public.password_heading'));

    $this->post(route('landing.public.unlock', ['slug' => 'investor-deck']), ['password' => 'open-sesame']);

    $this->get(route('landing.public.thanks', ['slug' => 'investor-deck']))
        ->assertOk()
        ->assertSee('Secret thanks');
});

it('refuses the submit endpoint while locked, and accepts it once unlocked', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $list->id,
        'captcha_enabled' => false,
    ]);

    $this->page->forceFill([
        'form_id' => $form->id,
        'content_html' => '<div '.LandingBlocks::FORM_PLACEHOLDER_ATTRIBUTE.'="1"></div>',
    ])->save();

    // A bot POSTing straight at the endpoint never saw the gate — 403.
    $this->postJson(route('landing.public.submit', ['slug' => 'investor-deck']), [
        'email' => 'bot@example.test',
    ])->assertForbidden();

    expect($list->subscribers()->withoutGlobalScopes()->count())->toBe(0);

    $this->post(route('landing.public.unlock', ['slug' => 'investor-deck']), ['password' => 'open-sesame']);

    $this->postJson(route('landing.public.submit', ['slug' => 'investor-deck']), [
        'email' => 'anna@example.test',
    ])->assertOk();

    expect($list->subscribers()->withoutGlobalScopes()->count())->toBe(1);
});

it('revokes an existing unlock when the password is rotated', function (): void {
    $this->post(route('landing.public.unlock', ['slug' => 'investor-deck']), ['password' => 'open-sesame']);
    $this->get(route('landing.public.show', ['slug' => 'investor-deck']))->assertSee('Secret numbers');

    $this->page->forceFill(['access_password' => Hash::make('new-secret')])->save();

    $this->get(route('landing.public.show', ['slug' => 'investor-deck']))
        ->assertOk()
        ->assertDontSee('Secret numbers');
});

it('leaves a page with no password completely public', function (): void {
    $open = LandingPage::factory()->published()->create([
        'customer_id' => $this->customer->id,
        'slug' => 'open-page',
        'content_html' => '<h1>Public copy</h1>',
        'access_password' => null,
    ]);

    $this->get(route('landing.public.show', ['slug' => 'open-page']))
        ->assertOk()
        ->assertSee('Public copy');

    // …and the unlock endpoint is a 404 there: it must not advertise itself.
    $this->post(route('landing.public.unlock', ['slug' => 'open-page']), ['password' => 'x'])
        ->assertNotFound();

    expect($open->isPasswordProtected())->toBeFalse();
});

it('stores the panel-entered password hashed and never echoes it back', function (): void {
    $page = LandingPage::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Gated',
        'slug' => 'gated',
    ]);

    Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
        ->fillForm(['access_password' => 'panel-secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    $page->refresh();

    expect($page->access_password)->not->toBe('panel-secret')
        ->and(Hash::check('panel-secret', (string) $page->access_password))->toBeTrue()
        ->and($page->isPasswordProtected())->toBeTrue();

    // Re-opening the record must not put the hash in the browser…
    Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertDontSee($page->access_password);

    // …and saving with the field left blank keeps the existing password.
    Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
        ->fillForm(['name' => 'Gated renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('panel-secret', (string) $page->fresh()->access_password))->toBeTrue();
});

it('clears the password only through the explicit remove toggle', function (): void {
    $page = LandingPage::factory()->create([
        'customer_id' => $this->customer->id,
        'slug' => 'removable',
        'access_password' => Hash::make('to-remove'),
    ]);

    Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
        ->fillForm(['remove_access_password' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->fresh()->isPasswordProtected())->toBeFalse();
});
