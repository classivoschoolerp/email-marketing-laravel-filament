<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Filament\Admin\Pages\ListPageDefaultsPage;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListPages;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListPageDefault;
use App\Models\User;
use App\Services\Lists\ListPageResolver;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

// F-3.16-001: the 3-layer merge — per-list override > admin default >
// translatable default, for content and subject alike.
it('resolves page content through the three layers with correct precedence', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $resolver = app(ListPageResolver::class);
    $type = ListPageType::SubscribePending;

    // Layer 3: translatable default.
    expect($resolver->content($list, $type))->toBe(__($type->defaultContentKey()));

    // Layer 2: admin per-install default overrides the translatable text.
    $resolver->saveDefault($type, '<h1>Install default 待到花开 🌷</h1>', null);
    expect($resolver->content($list, $type))->toBe('<h1>Install default 待到花开 🌷</h1>');

    // Layer 1: per-list override wins over everything.
    $resolver->save($list, $type, '<h1>List override</h1>', null, null);
    expect($resolver->content($list, $type))->toBe('<h1>List override</h1>');

    // Dropping the override falls back to the admin default…
    $resolver->reset($list, $type);
    expect($resolver->content($list, $type))->toBe('<h1>Install default 待到花开 🌷</h1>');

    // …and dropping the admin default falls back to the translatable layer.
    $resolver->resetDefault($type);
    expect($resolver->content($list, $type))->toBe(__($type->defaultContentKey()));

    // Subjects follow the same precedence (email page types).
    $emailType = ListPageType::WelcomeEmail;
    expect($resolver->subject($list, $emailType))->toBe(__($emailType->defaultSubjectKey()));

    $resolver->saveDefault($emailType, '<p>Hi</p>', 'Install subject');
    expect($resolver->subject($list, $emailType))->toBe('Install subject');

    $resolver->save($list, $emailType, '<p>Hi</p>', 'List subject', null);
    expect($resolver->subject($list, $emailType))->toBe('List subject');
});

// Admin defaults flow through to the public pages of uncustomized lists.
it('renders admin defaults on public pages and prefills the customer editor', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();

    app(ListPageResolver::class)->saveDefault(
        ListPageType::SubscribeForm,
        '<h1>Operator subscribe page for [LIST_NAME]</h1>',
        null,
    );

    $this->get(route('lists.public.subscribe', ['list_uid' => $list->uid]))
        ->assertOk()
        ->assertSee('Operator subscribe page for '.$list->name);

    // The WP-08 customer editor prefills the effective (admin) default.
    $this->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($customer);

    Livewire::test(ManageListPages::class, ['record' => $list->getRouteKey()])
        ->assertSuccessful()
        ->assertSet('data.content', '<h1>Operator subscribe page for [LIST_NAME]</h1>');
});

// F-3.16-001/002: the admin page edits defaults for the 11 page types and
// shows the full merge tag reference.
it('saves and resets install defaults from the admin page with the merge tag reference', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $page = Livewire::test(ListPageDefaultsPage::class)
        ->assertSuccessful()
        // Full merge tag reference panel (F-3.16-002).
        ->assertSee('[SUBSCRIBER_EMAIL]')
        ->assertSee('[CONFIRM_URL]')
        ->assertSee('[COMPANY_FULL_ADDRESS]')
        // Prefilled with the translatable default of the first type.
        ->assertSet('data.type', ListPageType::SubscribeForm->value)
        ->assertSet('data.content', __(ListPageType::SubscribeForm->defaultContentKey()));

    // Save a default for an email type (subject + content). The type switch
    // reloads the form, so it is set first, then the new values.
    $page->fillForm(['type' => ListPageType::WelcomeEmail->value])
        ->fillForm([
            'subject' => 'Welcome aboard 🎉',
            'content' => '<p>Operator welcome</p>',
        ])->call('save')->assertHasNoFormErrors();

    $row = ListPageDefault::query()->where('type', ListPageType::WelcomeEmail->value)->firstOrFail();
    expect($row->subject)->toBe('Welcome aboard 🎉')
        ->and($row->content)->toBe('<p>Operator welcome</p>');

    // Reset drops the row → translatable layer again.
    $page->call('resetToDefault');
    expect(ListPageDefault::query()->where('type', ListPageType::WelcomeEmail->value)->exists())->toBeFalse();
});
