<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignGroupResource\Pages\ManageCampaignGroups;
use App\Filament\Customer\Resources\SpintaxSnippetResource\Pages\ManageSpintaxSnippets;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\SpintaxSnippet;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

// §2.3.10 — campaign groups CRUD + assignment; §2.3.3 snippet library CRUD.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

// F-2.3.10-001 — group CRUD.
it('creates, renames and deletes campaign groups', function (): void {
    Livewire::test(ManageCampaignGroups::class)
        ->callAction('create', data: ['name' => 'Newsletters']);

    $group = CampaignGroup::query()->firstOrFail();
    expect($group->name)->toBe('Newsletters')
        ->and($group->customer_id)->toBe($this->customer->id)
        ->and($group->uid)->not->toBeEmpty();

    Livewire::test(ManageCampaignGroups::class)
        ->callTableAction('edit', $group, data: ['name' => 'Monthly newsletters']);
    expect($group->refresh()->name)->toBe('Monthly newsletters');

    Livewire::test(ManageCampaignGroups::class)
        ->callTableAction('delete', $group);
    expect(CampaignGroup::query()->count())->toBe(0);
});

// F-2.3.10-001 — deleting a group detaches its campaigns.
it('detaches campaigns when their group is deleted', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $group = CampaignGroup::factory()->create(['customer_id' => $this->customer->id]);
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
        'campaign_group_id' => $group->id,
    ]);

    $group->delete();

    expect($campaign->refresh()->campaign_group_id)->toBeNull()
        ->and($campaign->trashed())->toBeFalse();
});

// Tenancy — B never sees A's groups.
it('never lists another tenants groups', function (): void {
    $mine = CampaignGroup::factory()->create(['customer_id' => $this->customer->id]);

    $other = Customer::factory()->create();
    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): CampaignGroup => CampaignGroup::factory()->create(['customer_id' => $other->id]),
    );

    Livewire::test(ManageCampaignGroups::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

// F-2.3.3-023 — snippet CRUD with permutation counts.
it('creates, edits and deletes spintax snippets', function (): void {
    Livewire::test(ManageSpintaxSnippets::class)
        ->callAction('create', data: [
            'name' => 'Greeting',
            'content' => '{Hi|Hello|Hey} {there|friend}',
        ]);

    $snippet = SpintaxSnippet::query()->firstOrFail();
    expect($snippet->customer_id)->toBe($this->customer->id)
        ->and($snippet->content)->toBe('{Hi|Hello|Hey} {there|friend}');

    Livewire::test(ManageSpintaxSnippets::class)
        ->callTableAction('edit', $snippet, data: [
            'name' => 'Greeting v2',
            'content' => '{Hi|Hello}',
        ]);
    expect($snippet->refresh()->name)->toBe('Greeting v2');

    Livewire::test(ManageSpintaxSnippets::class)
        ->callTableAction('delete', $snippet);
    expect(SpintaxSnippet::query()->count())->toBe(0);
});

// Tenancy — B never sees A's snippets.
it('never lists another tenants snippets', function (): void {
    $mine = SpintaxSnippet::factory()->create(['customer_id' => $this->customer->id]);

    $other = Customer::factory()->create();
    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): SpintaxSnippet => SpintaxSnippet::factory()->create(['customer_id' => $other->id]),
    );

    Livewire::test(ManageSpintaxSnippets::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});
