<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\GalleryTemplateResource;
use App\Filament\Admin\Resources\GalleryTemplateResource\Pages\CreateGalleryTemplate;
use App\Filament\Admin\Resources\GalleryTemplateResource\Pages\EditGalleryTemplate;
use App\Filament\Admin\Resources\GalleryTemplateResource\Pages\ListGalleryTemplates;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Jobs\Templates\ImportGalleryPackJob;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\EmailTemplate;
use App\Models\Role;
use App\Models\TemplateCategory;
use App\Models\User;
use App\Services\Templates\GalleryPackImporter;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** Admin user holding the templates.manage permission (EmailTemplatePolicy). */
function wp10_templates_admin(): User
{
    $user = User::factory()->create();

    $role = Role::create(['name' => 'Templates Ops '.uniqid(), 'guard_name' => 'admin']);
    $role->givePermissionTo(Permission::findOrCreate('templates.manage', 'admin'));
    $role->users()->attach($user->getKey());

    return $user;
}

function wp10_acting_admin(?User $user = null): User
{
    $user ??= wp10_templates_admin();

    test()->actingAs($user, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $user;
}

// F-3.14-001 — admin gallery CRUD requires the templates.manage permission
it('renders the gallery for permitted admins and denies others', function (): void {
    wp10_acting_admin();

    $this->get(GalleryTemplateResource::getUrl('index', panel: 'admin'))->assertOk();

    $this->actingAs(User::factory()->create(), 'admin');

    $this->get(GalleryTemplateResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

// F-3.14-001 — only system rows are managed here
it('lists system templates only, never customer templates', function (): void {
    wp10_acting_admin();

    $system = EmailTemplate::factory()->system()->create();
    $customerOwned = EmailTemplate::factory()->create();

    Livewire::test(ListGalleryTemplates::class)
        ->assertCanSeeTableRecords([$system])
        ->assertCanNotSeeTableRecords([$customerOwned]);
});

// F-3.14-001 — create a system template (customer_id NULL)
it('creates a system template with a system category', function (): void {
    wp10_acting_admin();

    $category = TemplateCategory::factory()->system()->create();

    Livewire::test(CreateGalleryTemplate::class)
        ->fillForm([
            'name' => 'System welcome',
            'category_id' => $category->id,
            'builder' => 'html',
            'sort_order' => 1,
            'content_html' => '<p>Hello {SUBSCRIBER_NAME}</p><a href="{UNSUBSCRIBE_URL}">bye</a>',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = EmailTemplate::withoutTenancy()->where('name', 'System welcome')->first();

    expect($template)->not->toBeNull()
        ->and($template->customer_id)->toBeNull()
        ->and($template->category_id)->toBe($category->id)
        ->and($template->is_gallery)->toBeFalse() // publishing is explicit (F-3.14-002)
        ->and($template->missing_unsubscribe_tag)->toBeFalse();
});

// F-3.14-002 — publish to all customers or to specific groups
it('publishes a system template with an optional group restriction', function (): void {
    wp10_acting_admin();

    $group = CustomerGroup::factory()->create();
    $template = EmailTemplate::factory()->system()->create();

    Livewire::test(ListGalleryTemplates::class)
        ->callTableAction('publish', $template, data: ['groups' => [$group->id]]);

    $template->refresh();

    expect($template->is_gallery)->toBeTrue()
        ->and($template->gallery_customer_groups)->toBe([$group->id]);

    // Unpublish hides it again.
    Livewire::test(ListGalleryTemplates::class)
        ->callTableAction('unpublish', $template);

    expect($template->refresh()->is_gallery)->toBeFalse();
});

// F-3.14-002 — group restriction controls which customers can browse the row
it('restricts gallery visibility to the published customer groups', function (): void {
    $group = CustomerGroup::factory()->create();
    $inGroup = Customer::factory()->create(['customer_group_id' => $group->id]);
    $outside = Customer::factory()->create();

    $restricted = EmailTemplate::factory()->gallery()->create([
        'gallery_customer_groups' => [$group->id],
    ]);
    $unrestricted = EmailTemplate::factory()->gallery()->create();

    $current = app(CurrentCustomer::class);

    $visibleToInGroup = $current->runAs(
        $inGroup,
        fn () => EmailTemplate::browsable()->pluck('id')->all(),
    );
    $visibleToOutside = $current->runAs(
        $outside,
        fn () => EmailTemplate::browsable()->pluck('id')->all(),
    );

    expect($visibleToInGroup)->toContain($restricted->id)
        ->and($visibleToInGroup)->toContain($unrestricted->id)
        ->and($visibleToOutside)->not->toContain($restricted->id)
        ->and($visibleToOutside)->toContain($unrestricted->id);

    expect($restricted->isVisibleToCustomer($inGroup))->toBeTrue()
        ->and($restricted->isVisibleToCustomer($outside))->toBeFalse();
});

// F-3.14-004 — featured flag and explicit ordering
it('sets featured and sort order on gallery templates', function (): void {
    wp10_acting_admin();

    $template = EmailTemplate::factory()->system()->create(['sort_order' => 0]);

    Livewire::test(EditGalleryTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm([
            'is_featured' => true,
            'sort_order' => 7,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $template->refresh();

    expect($template->is_featured)->toBeTrue()
        ->and($template->sort_order)->toBe(7);

    // The gallery lists by sort_order by default.
    $early = EmailTemplate::factory()->system()->create(['sort_order' => 1]);

    Livewire::test(ListGalleryTemplates::class)
        ->assertCanSeeTableRecords([$early, $template], inOrder: true);
});

// F-3.14-003 — bulk pack import is queued, never synchronous
it('queues the gallery pack import job on the imports queue', function (): void {
    Queue::fake();

    ImportGalleryPackJob::dispatch('tmp/gallery-packs/pack.zip');

    Queue::assertPushedOn(Queues::IMPORTS, ImportGalleryPackJob::class);

    wp10_acting_admin();

    Livewire::test(ListGalleryTemplates::class)
        ->assertActionExists('import_pack');
});

// F-3.14-003 — the importer turns each pack folder into one unpublished system template
it('imports a template pack into system templates', function (): void {
    Storage::fake('public');

    $path = tempnam(sys_get_temp_dir(), 'wp10-pack-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('welcome/index.html', '<html><body><img src="logo.png">{UNSUBSCRIBE_URL}</body></html>');
    $zip->addFromString('welcome/logo.png', 'PNG');
    $zip->addFromString('welcome/template.json', json_encode([
        'name' => 'Welcome pack template',
        'category' => 'Onboarding',
        'featured' => true,
        'sort_order' => 5,
    ]));
    $zip->addFromString('digest/newsletter.html', '<p>{UNSUBSCRIBE_URL}</p>');
    $zip->addFromString('empty-folder/readme.txt', 'no html here');
    $zip->close();

    try {
        $created = app(GalleryPackImporter::class)->import($path);
    } finally {
        @unlink($path);
    }

    expect($created)->toHaveCount(2);

    $welcome = EmailTemplate::withoutTenancy()->where('name', 'Welcome pack template')->first();

    expect($welcome)->not->toBeNull()
        ->and($welcome->customer_id)->toBeNull()
        ->and($welcome->is_gallery)->toBeFalse() // published explicitly, never on import
        ->and($welcome->is_featured)->toBeTrue()
        ->and($welcome->sort_order)->toBe(5)
        ->and($welcome->content_html)->toContain('/storage/gallery/templates/'.$welcome->uid.'/logo.png');

    $category = TemplateCategory::withoutTenancy()->where('name', 'Onboarding')->first();

    expect($category)->not->toBeNull()
        ->and($category->customer_id)->toBeNull()
        ->and($welcome->category_id)->toBe($category->id);

    expect(EmailTemplate::withoutTenancy()->where('name', 'Digest')->exists())->toBeTrue();
});

// §3.14 — customers can never mutate gallery rows; admins never mutate customer rows
it('enforces the read-only gallery policy in both directions', function (): void {
    $customer = Customer::factory()->create();
    $gallery = EmailTemplate::factory()->gallery()->create();
    $owned = EmailTemplate::factory()->create(['customer_id' => $customer->id]);

    expect(Gate::forUser($customer)->allows('view', $gallery))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('update', $gallery))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('delete', $gallery))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('update', $owned))->toBeTrue();

    $admin = wp10_templates_admin();

    expect(Gate::forUser($admin)->allows('update', $gallery))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $owned))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $owned))->toBeFalse();

    $powerless = User::factory()->create();

    expect(Gate::forUser($powerless)->allows('update', $gallery))->toBeFalse();
});

// §3.14 — a customer opening a gallery row's edit page is refused
it('blocks the customer edit page for gallery templates', function (): void {
    $customer = Customer::factory()->create();
    $gallery = EmailTemplate::factory()->gallery()->create();

    $this->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $this->get(EmailTemplateResource::getUrl('edit', ['record' => $gallery], panel: 'customer'))
        ->assertForbidden();
});

// Cross-tenant isolation — a customer can never reach another tenant's template
it('returns 404 for another tenants template pages', function (): void {
    $victim = Customer::factory()->create();
    $foreign = EmailTemplate::factory()->create(['customer_id' => $victim->id]);

    $attacker = Customer::factory()->create();
    $this->actingAs($attacker, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $this->get(EmailTemplateResource::getUrl('edit', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();

    $this->get(EmailTemplateResource::getUrl('builder', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();
});
