<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListPages;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListPage;
use App\Services\Lists\ListPageResolver;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->resolver = app(ListPageResolver::class);
});

// F-2.2.12-001: the 11 P1 page types of §1.5, plus the two P2 pages added by
// WP-42 — preference center (F-1.5-012) and resubscribe (F-1.5-013).
it('models the 11 public list page types', function (): void {
    expect(array_map(fn (ListPageType $type): string => $type->value, ListPageType::cases()))->toBe([
        'subscribe-form', 'subscribe-pending', 'subscribe-confirm', 'subscribe-confirm-email',
        'welcome-email', 'update-profile', 'unsubscribe-form', 'unsubscribe-confirm',
        'unsubscribe-success', 'redirect', 'list-not-found',
        'preference-center', 'resubscribe',
    ]);

    // Email types carry a subject; only they do.
    expect(ListPageType::SubscribeConfirmEmail->isEmail())->toBeTrue()
        ->and(ListPageType::WelcomeEmail->isEmail())->toBeTrue()
        ->and(ListPageType::SubscribeForm->isEmail())->toBeFalse();
});

// F-2.2.12-001/004: defaults resolve for every type; overrides win; reset restores.
it('resolves default content, merges overrides and resets to default', function (): void {
    foreach (ListPageType::cases() as $type) {
        if ($type->isRedirectOnly()) {
            expect($this->resolver->content($this->list, $type))->toBe('');

            continue;
        }

        // Every default template exists and is translatable HTML.
        expect($this->resolver->content($this->list, $type))->not->toBe('')
            ->and($this->resolver->content($this->list, $type))->not->toContain('lists.pages.defaults');
    }

    // Defaults carry merge tags for WP-09 rendering.
    expect($this->resolver->content($this->list, ListPageType::SubscribePending))->toContain('[SUBSCRIBER_EMAIL]')
        ->and($this->resolver->subject($this->list, ListPageType::SubscribeConfirmEmail))->toContain('[LIST_NAME]')
        ->and($this->resolver->subject($this->list, ListPageType::SubscribeForm))->toBeNull();

    // Override wins…
    $this->resolver->save($this->list, ListPageType::SubscribeConfirm, '<h1>Danke!</h1>', null, null);
    expect($this->resolver->content($this->list, ListPageType::SubscribeConfirm))->toBe('<h1>Danke!</h1>');

    // …an empty override falls back to the default…
    $this->resolver->save($this->list, ListPageType::SubscribeConfirm, '   ', null, null);
    expect($this->resolver->content($this->list, ListPageType::SubscribeConfirm))
        ->toBe($this->resolver->defaultContent(ListPageType::SubscribeConfirm));

    // …and reset removes the row entirely (F-2.2.12-004).
    $this->resolver->save($this->list, ListPageType::SubscribeConfirm, '<h1>Again</h1>', null, null);
    $this->resolver->reset($this->list, ListPageType::SubscribeConfirm);
    expect(ListPage::query()->where('list_id', $this->list->id)->where('type', 'subscribe-confirm')->exists())->toBeFalse()
        ->and($this->resolver->content($this->list, ListPageType::SubscribeConfirm))
        ->toBe($this->resolver->defaultContent(ListPageType::SubscribeConfirm));
});

// F-2.2.12-005: per-page redirect override.
it('stores and resolves the per-page redirect override', function (): void {
    expect($this->resolver->redirectUrl($this->list, ListPageType::UnsubscribeSuccess))->toBeNull();

    $this->resolver->save($this->list, ListPageType::UnsubscribeSuccess, null, null, 'https://example.com/bye');

    expect($this->resolver->redirectUrl($this->list, ListPageType::UnsubscribeSuccess))->toBe('https://example.com/bye');

    // Email types never get a redirect (F-2.2.12-005 applies to pages).
    $this->resolver->save($this->list, ListPageType::WelcomeEmail, '<p>Hi</p>', 'Welcome', 'https://example.com/nope');
    expect($this->resolver->redirectUrl($this->list, ListPageType::WelcomeEmail))->toBeNull();
});

// F-2.2.12-001/002/003: the editor page saves per-type content + subject.
it('edits a page through the Filament editor and resets it to default', function (): void {
    $component = Livewire::test(ManageListPages::class, ['record' => $this->list->getRouteKey()])
        ->assertSuccessful();

    // Switching type loads the default welcome-email subject into the form.
    $component->fillForm(['type' => ListPageType::WelcomeEmail->value])
        ->assertFormSet(['subject' => __('lists.pages.defaults.welcome-email.subject')]);

    $component->fillForm([
        'subject' => 'Welcome aboard 🎉',
        'content' => '<p>こんにちは [SUBSCRIBER_EMAIL]</p>',
    ])->call('save')->assertHasNoFormErrors();

    $row = ListPage::query()
        ->where('list_id', $this->list->id)
        ->where('type', ListPageType::WelcomeEmail->value)
        ->firstOrFail();

    expect($row->subject)->toBe('Welcome aboard 🎉')
        ->and($row->content)->toBe('<p>こんにちは [SUBSCRIBER_EMAIL]</p>')
        ->and($this->resolver->subject($this->list, ListPageType::WelcomeEmail))->toBe('Welcome aboard 🎉');

    // Reset-to-default drops the override (F-2.2.12-004).
    $component->call('resetToDefault');
    expect(ListPage::query()->where('list_id', $this->list->id)->where('type', ListPageType::WelcomeEmail->value)->exists())
        ->toBeFalse();
});

// Cross-tenant: customer B cannot open A's pages editor.
it('denies another tenant the pages editor', function (): void {
    $url = EmailListResource::getUrl('pages', ['record' => $this->list]);

    $other = Customer::factory()->create();
    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);

    $this->get($url)->assertNotFound();
});
