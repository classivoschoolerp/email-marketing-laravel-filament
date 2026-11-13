<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Lists\ListPageResolver;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
});

// F-1.5-011: unknown uid → branded 404 page, not a bare error.
it('shows the branded list-not-found page for an unknown list uid', function (): void {
    $this->get(route('lists.public.subscribe', ['list_uid' => 'no-such-list']))
        ->assertNotFound()
        ->assertSee('List unavailable');
});

// F-1.5-011: hidden and disabled lists get the same treatment, honoring
// the list's own customized not-found content.
it('shows the branded page for hidden or disabled lists with per-list content', function (): void {
    $hidden = EmailList::factory()->for($this->customer)->hidden()->create();

    $this->get(route('lists.public.subscribe', ['list_uid' => $hidden->uid]))
        ->assertNotFound()
        ->assertSee('List unavailable');

    app(ListPageResolver::class)->save(
        $hidden, ListPageType::ListNotFound, '<h1>Custom gone page 🚪</h1>', null, null,
    );

    $this->get(route('lists.public.subscribe', ['list_uid' => $hidden->uid]))
        ->assertNotFound()
        ->assertSee('Custom gone page 🚪');

    $disabled = EmailList::factory()->for($this->customer)->create([
        'status' => EmailList::STATUS_PENDING_DELETE,
    ]);

    $this->get(route('lists.public.subscribe', ['list_uid' => $disabled->uid]))
        ->assertNotFound()
        ->assertSee('List unavailable');
});
