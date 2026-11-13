<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\TemplateCategoryResource;
use App\Filament\Customer\Resources\TemplateCategoryResource\Pages\ManageTemplateCategories;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\TemplateCategory;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

// F-2.5.2-001 — page renders
it('renders the categories page', function (): void {
    TemplateCategory::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Newsletters']);

    $this->get(TemplateCategoryResource::getUrl('index', panel: 'customer'))
        ->assertOk()
        ->assertSee('Newsletters');
});

// F-2.5.2-001 — create
it('creates a category', function (): void {
    Livewire::test(ManageTemplateCategories::class)
        ->callAction('create', data: [
            'name' => 'Promotions',
            'sort_order' => 3,
        ])
        ->assertHasNoActionErrors();

    $category = TemplateCategory::query()->where('name', 'Promotions')->first();

    expect($category)->not->toBeNull()
        ->and($category->customer_id)->toBe($this->customer->id)
        ->and($category->sort_order)->toBe(3)
        ->and($category->uid)->not->toBeEmpty();
});

// F-2.5.2-001 — validation
it('requires a category name', function (): void {
    Livewire::test(ManageTemplateCategories::class)
        ->callAction('create', data: ['name' => '', 'sort_order' => 0])
        ->assertHasActionErrors(['name' => 'required']);
});

// F-2.5.2-001 — update incl. sort order
it('updates a category and its sort order', function (): void {
    $category = TemplateCategory::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(ManageTemplateCategories::class)
        ->callTableAction('edit', $category, data: [
            'name' => 'Renamed',
            'sort_order' => 9,
        ])
        ->assertHasNoTableActionErrors();

    expect($category->refresh()->name)->toBe('Renamed')
        ->and($category->sort_order)->toBe(9);
});

// F-2.5.2-001 — delete; templates keep existing but lose the category (nullOnDelete)
it('deletes a category without deleting its templates', function (): void {
    $category = TemplateCategory::factory()->create(['customer_id' => $this->customer->id]);
    $template = EmailTemplate::factory()->create([
        'customer_id' => $this->customer->id,
        'category_id' => $category->id,
    ]);

    Livewire::test(ManageTemplateCategories::class)
        ->callTableAction('delete', $category);

    $this->assertDatabaseMissing('template_categories', ['id' => $category->id]);

    expect($template->refresh()->category_id)->toBeNull();
});

// F-2.5.2-001 — assignment count + sort-order default listing
it('shows the template count and sorts by sort order', function (): void {
    $second = TemplateCategory::factory()->create(['customer_id' => $this->customer->id, 'sort_order' => 2]);
    $first = TemplateCategory::factory()->create(['customer_id' => $this->customer->id, 'sort_order' => 1]);

    EmailTemplate::factory()->count(2)->create([
        'customer_id' => $this->customer->id,
        'category_id' => $first->id,
    ]);

    Livewire::test(ManageTemplateCategories::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertTableColumnStateSet('templates_count', 2, record: $first->getKey());
});

// Tenancy — other tenants' and system categories are not manageable here
it('never lists other tenants or system categories', function (): void {
    $mine = TemplateCategory::factory()->create(['customer_id' => $this->customer->id]);
    $other = TemplateCategory::factory()->create(); // another customer's category
    $system = app(CurrentCustomer::class)->runAs(
        null,
        fn (): TemplateCategory => TemplateCategory::factory()->system()->create(),
    );

    Livewire::test(ManageTemplateCategories::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other, $system]);
});
