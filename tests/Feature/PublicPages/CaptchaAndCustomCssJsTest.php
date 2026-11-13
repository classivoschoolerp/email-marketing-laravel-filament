<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListPages;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Lists\ListPageResolver;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create();
});

// F-1.5-016: the captcha widget renders on public forms when configured.
it('renders the configured captcha widget on the public forms', function (): void {
    config()->set('emp.captcha.driver', 'recaptcha-v2');
    config()->set('emp.captcha.site_key', 'site-key-123');
    config()->set('emp.captcha.secret_key', 'secret-key-123');

    $subscriber = Subscriber::factory()->for($this->customer)->for($this->list, 'list')->create();

    $this->get(route('lists.public.subscribe', ['list_uid' => $this->list->uid]))
        ->assertOk()
        ->assertSee('data-sitekey="site-key-123"', false)
        ->assertSee('recaptcha/api.js');

    $this->get(route('lists.public.unsubscribe', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $subscriber->uid,
    ]))
        ->assertOk()
        ->assertSee('data-sitekey="site-key-123"', false);
});

// F-1.5-016: a failing captcha blocks the unsubscribe POST.
it('rejects public form submissions when the captcha verification fails', function (): void {
    config()->set('emp.captcha.driver', 'recaptcha-v2');
    config()->set('emp.captcha.site_key', 'site-key-123');
    config()->set('emp.captcha.secret_key', 'secret-key-123');

    Http::fake(['*' => Http::response(['success' => false])]);

    $subscriber = Subscriber::factory()->for($this->customer)->for($this->list, 'list')->create();

    $this->post(route('lists.public.unsubscribe.confirm', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $subscriber->uid,
    ]), ['g-recaptcha-response' => 'bad-token'])
        ->assertRedirect()
        ->assertSessionHasErrors('captcha');

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed);
});

// F-1.5-017: custom CSS/JS saved through the WP-08 editor, rendered publicly.
it('saves custom CSS/JS in the pages editor and injects them safely on the public page', function (): void {
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    Livewire::test(ManageListPages::class, ['record' => $this->list->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'custom_css' => "h1 { color: rebeccapurple; }\n</style><script>alert('css-breakout')</script>",
            'custom_js' => "console.log('hello 🎈');\n</script><script>alert('js-breakout')</script>",
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $record = app(ListPageResolver::class)->record($this->list, ListPageType::SubscribeForm);
    expect($record?->custom_css)->toContain('rebeccapurple')
        ->and($record?->custom_js)->toContain('hello 🎈');

    // Fresh session (public visitor, not the panel user).
    $this->app->make('auth')->forgetGuards();
    app(CurrentCustomer::class)->set(null);

    $response = $this->get(route('lists.public.subscribe', ['list_uid' => $this->list->uid]));

    $response->assertOk()
        ->assertSee('h1 { color: rebeccapurple; }', false)
        ->assertSee("console.log('hello 🎈');", false)
        // Context-breakout sequences are stripped (escaped-safe injection).
        ->assertDontSee("</style><script>alert('css-breakout')", false)
        ->assertDontSee("</script><script>alert('js-breakout')", false);
});
