<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\AnnouncementResource;
use App\Filament\Admin\Resources\AnnouncementResource\Pages\ManageAnnouncements;
use App\Models\Announcement;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// WP-18 — §2.1 F-2.1-010: the admin CRUD behind the customer-dashboard
// announcements (customers.* matrix permissions).

beforeEach(function (): void {
    $this->admin = AdminTestHelpers::actingAdmin(['customers.view', 'customers.update']);
});

it('creates an announcement from the manage page', function (): void {
    Livewire::test(ManageAnnouncements::class)
        ->callAction('create', data: [
            'title' => 'Scheduled maintenance',
            'body' => '<p>Sunday 02:00 UTC.</p>',
            'level' => Announcement::LEVEL_WARNING,
            'active' => true,
            'dismissible' => true,
        ])
        ->assertHasNoActionErrors();

    $announcement = Announcement::query()->firstOrFail();

    expect($announcement->title)->toBe('Scheduled maintenance')
        ->and($announcement->level)->toBe(Announcement::LEVEL_WARNING)
        ->and($announcement->active)->toBeTrue();
});

it('edits and deletes announcements', function (): void {
    $announcement = Announcement::query()->create(['title' => 'Old title', 'body' => '<p>x</p>']);

    Livewire::test(ManageAnnouncements::class)
        ->callTableAction('edit', $announcement, data: ['title' => 'New title', 'active' => false])
        ->assertHasNoTableActionErrors();

    expect($announcement->refresh()->title)->toBe('New title')
        ->and($announcement->active)->toBeFalse();

    Livewire::test(ManageAnnouncements::class)
        ->callTableAction('delete', $announcement);

    expect(Announcement::query()->count())->toBe(0);
});

it('denies the announcements module without customers.view', function (): void {
    AdminTestHelpers::actingAdmin();

    $this->get(AnnouncementResource::getUrl('index'))->assertForbidden();
});
