<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\ListPageType;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListEmailLists;
use App\Jobs\Lists\CopyListJob;
use App\Jobs\Lists\PurgeListJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListPage;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\ListCopyService;
use App\Services\Lists\ListPageResolver;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

it('queues the copy job from the table action (F-2.2.14-001)', function (): void {
    Queue::fake();

    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(ListEmailLists::class)
        ->callTableAction('copy', $list, data: ['with_subscribers' => true]);

    Queue::assertPushed(CopyListJob::class, fn (CopyListJob $job): bool => $job->listId === $list->id
        && $job->customerId === $this->customer->id
        && $job->withSubscribers === true);
});

// F-2.2.14-001 "with fields, segments, pages": the customised §2.2.12 pages
// must travel with the copy, uncustomised ones must stay on the defaults.
it('copies the list\'s customised pages', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Origin']);
    $resolver = app(ListPageResolver::class);

    $resolver->save(
        $list,
        ListPageType::SubscribeForm,
        '<p>Join the Origin list</p>',
        null,
        null,
        '.emp-form { color: rebeccapurple; }',
        'console.log("origin");',
    );
    $resolver->save(
        $list,
        ListPageType::SubscribeConfirmEmail,
        '<p>Confirm: [CONFIRM_URL]</p>',
        'Please confirm your Origin subscription',
        null,
    );
    $resolver->save($list, ListPageType::Redirect, null, null, 'https://origin.example/thanks');

    expect(ListPage::withoutTenancy()->where('list_id', $list->id)->count())->toBe(3);

    $copy = app(ListCopyService::class)->copy($list);

    $copiedPages = ListPage::withoutTenancy()->where('list_id', $copy->id)->get();

    expect($copiedPages)->toHaveCount(3)
        // Originals untouched.
        ->and(ListPage::withoutTenancy()->where('list_id', $list->id)->count())->toBe(3)
        ->and($copiedPages->pluck('type')->map(fn ($t): string => $t->value)->sort()->values()->all())->toBe([
            ListPageType::Redirect->value,
            ListPageType::SubscribeConfirmEmail->value,
            ListPageType::SubscribeForm->value,
        ])
        ->and($copiedPages->every(fn (ListPage $p): bool => (int) $p->customer_id === $this->customer->id))->toBeTrue();

    // The copy resolves to the customised content, not the shipped defaults.
    expect($resolver->content($copy, ListPageType::SubscribeForm))->toBe('<p>Join the Origin list</p>')
        ->and($resolver->customCss($copy, ListPageType::SubscribeForm))->toBe('.emp-form { color: rebeccapurple; }')
        ->and($resolver->customJs($copy, ListPageType::SubscribeForm))->toBe('console.log("origin");')
        ->and($resolver->subject($copy, ListPageType::SubscribeConfirmEmail))->toBe('Please confirm your Origin subscription')
        ->and($resolver->redirectUrl($copy, ListPageType::Redirect))->toBe('https://origin.example/thanks')
        // An uncustomised type has no row on either list and falls back.
        ->and(ListPage::withoutTenancy()->where('list_id', $copy->id)->where('type', ListPageType::WelcomeEmail->value)->exists())->toBeFalse();
});

it('copies fields, options, segments and optionally subscribers', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Origin']);

    $field = ListField::factory()->for($list, 'list')->type(ListFieldType::Dropdown)->create(['tag' => 'PLAN']);
    $field->options()->createMany([
        ['customer_id' => $this->customer->id, 'label' => 'Free', 'value' => 'free', 'sort_order' => 0],
        ['customer_id' => $this->customer->id, 'label' => 'Pro', 'value' => 'pro', 'sort_order' => 1],
    ]);

    $segment = ListSegment::factory()->for($list, 'list')->create();
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'field',
        'list_field_id' => $field->id,
        'operator' => 'is',
        'value' => 'pro',
        'sort_order' => 0,
    ]);

    $subscriber = Subscriber::factory()->for($list, 'list')->create(['email' => 'keep@x.example']);
    app(FieldValueService::class)->set($subscriber, $field, 'pro');

    // Without subscribers.
    $bare = app(ListCopyService::class)->copy($list, withSubscribers: false);

    expect($bare->fields()->count())->toBe(1)
        ->and($bare->fields()->first()->options()->count())->toBe(2)
        ->and($bare->segments()->count())->toBe(1)
        ->and($bare->subscribers()->count())->toBe(0)
        ->and($bare->uid)->not->toBe($list->uid);

    // Segment condition field ids are remapped onto the copied field.
    $copiedCondition = $bare->segments()->first()->conditions()->first();
    expect($copiedCondition->list_field_id)->toBe($bare->fields()->first()->id);

    // With subscribers (via the queued job path).
    (new CopyListJob($this->customer->id, $list->id, true, (string) Str::uuid()))
        ->handle(app(CurrentCustomer::class), app(ListCopyService::class));

    $full = EmailList::query()
        ->where('name', __('lists.copy.default_name', ['name' => 'Origin']))
        ->orderByDesc('id')
        ->firstOrFail();

    expect($full->subscribers()->count())->toBe(1);

    $copiedSubscriber = $full->subscribers()->first();
    $copiedField = $full->fields()->first();

    expect($copiedSubscriber->email)->toBe('keep@x.example')
        ->and($copiedSubscriber->uid)->not->toBe($subscriber->uid)
        ->and(app(FieldValueService::class)->get($copiedSubscriber, $copiedField))->toBe('pro');
});

it('requires typing the exact list name to delete (F-2.2.14-002)', function (): void {
    Queue::fake();

    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Precious']);

    Livewire::test(ListEmailLists::class)
        ->callTableAction('delete', $list, data: ['confirm_name' => 'Wrong name'])
        ->assertHasTableActionErrors(['confirm_name']);

    expect(EmailList::query()->whereKey($list->id)->exists())->toBeTrue();
    Queue::assertNotPushed(PurgeListJob::class);
});

it('soft-deletes the list and queues the purge on confirmed delete', function (): void {
    Queue::fake();

    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Old list']);

    Livewire::test(ListEmailLists::class)
        ->callTableAction('delete', $list, data: ['confirm_name' => 'Old list'])
        ->assertHasNoTableActionErrors();

    expect(EmailList::query()->whereKey($list->id)->exists())->toBeFalse()
        ->and(EmailList::withTrashed()->whereKey($list->id)->first()->status)
        ->toBe(EmailList::STATUS_PENDING_DELETE);

    Queue::assertPushed(PurgeListJob::class, fn (PurgeListJob $job): bool => $job->listId === $list->id);
});

it('hard-purges the list with fields, segments and subscribers in the background', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();
    $field = ListField::factory()->for($list, 'list')->create();
    $segment = ListSegment::factory()->for($list, 'list')->create();
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'status',
        'operator' => 'is',
        'value' => 'confirmed',
        'sort_order' => 0,
    ]);
    $subscriber = Subscriber::factory()->for($list, 'list')->create();
    app(FieldValueService::class)->set($subscriber, $field, 'x');

    $list->delete();

    (new PurgeListJob($this->customer->id, $list->id, (string) Str::uuid()))->handle();

    expect(EmailList::withoutTenancy()->withTrashed()->whereKey($list->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->withTrashed()->where('list_id', $list->id)->exists())->toBeFalse()
        ->and(ListField::withoutTenancy()->where('list_id', $list->id)->exists())->toBeFalse()
        ->and(ListSegment::withoutTenancy()->where('list_id', $list->id)->exists())->toBeFalse();
});

it('does not let another tenant copy or delete a foreign list', function (): void {
    Queue::fake();

    $foreign = EmailList::factory()->create();

    Livewire::test(ListEmailLists::class)
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableActionHidden('delete', $foreign);

    expect(EmailList::withoutTenancy()->whereKey($foreign->id)->exists())->toBeTrue();
    Queue::assertNotPushed(PurgeListJob::class);
});
