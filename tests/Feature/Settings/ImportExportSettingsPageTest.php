<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\ImportExportSettingsPage;
use App\Models\User;
use App\Settings\ImportExportSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the import/export settings page for an admin', function (): void {
    $this->get(ImportExportSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.import_export.title'));
});

it('shows the seeded defaults in the form', function (): void {
    Livewire::test(ImportExportSettingsPage::class)
        ->assertFormSet([
            'max_import_file_size_mb' => 50,
            'allowed_import_extensions' => ['csv', 'txt', 'xlsx'],
            'import_batch_size' => 500,
            'import_memory_limit_mb' => 256,
            'import_max_execution_seconds' => 300,
            'export_batch_size' => 1000,
            'export_max_rows_per_file' => 100000,
            'import_from_url_enabled' => false,
            'import_from_database_enabled' => false,
            'export_retention_days' => 7,
        ]);
});

it('persists import/export settings', function (): void {
    Livewire::test(ImportExportSettingsPage::class)
        ->fillForm([
            'max_import_file_size_mb' => 120,
            'allowed_import_extensions' => ['csv', 'tsv'],
            'import_batch_size' => 2500,
            'import_memory_limit_mb' => 512,
            'import_max_execution_seconds' => 0,
            'export_batch_size' => 5000,
            'export_max_rows_per_file' => 250000,
            'import_from_url_enabled' => true,
            'import_from_database_enabled' => true,
            'export_retention_days' => 30,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(ImportExportSettings::class)->refresh();

    expect($settings->max_import_file_size_mb)->toBe(120)
        ->and($settings->allowed_import_extensions)->toBe(['csv', 'tsv'])
        ->and($settings->import_batch_size)->toBe(2500)
        ->and($settings->import_memory_limit_mb)->toBe(512)
        ->and($settings->import_max_execution_seconds)->toBe(0)
        ->and($settings->export_batch_size)->toBe(5000)
        ->and($settings->export_max_rows_per_file)->toBe(250000)
        ->and($settings->import_from_url_enabled)->toBeTrue()
        ->and($settings->import_from_database_enabled)->toBeTrue()
        ->and($settings->export_retention_days)->toBe(30);
});

it('rejects invalid import/export input', function (): void {
    Livewire::test(ImportExportSettingsPage::class)
        ->fillForm([
            'max_import_file_size_mb' => 0,
            'allowed_import_extensions' => ['exe!!'],
            'import_batch_size' => 0,
            'import_memory_limit_mb' => 1,
            'import_max_execution_seconds' => -5,
            'export_batch_size' => 0,
            'export_max_rows_per_file' => 0,
            'export_retention_days' => 0,
        ])
        ->call('save')
        ->assertHasFormErrors([
            'max_import_file_size_mb',
            'allowed_import_extensions.0',
            'import_batch_size',
            'import_memory_limit_mb',
            'import_max_execution_seconds',
            'export_batch_size',
            'export_max_rows_per_file',
            'export_retention_days',
        ]);

    expect(app(ImportExportSettings::class)->refresh()->max_import_file_size_mb)->toBe(50);
});

it('requires at least one allowed import extension', function (): void {
    Livewire::test(ImportExportSettingsPage::class)
        ->fillForm(['allowed_import_extensions' => []])
        ->call('save')
        ->assertHasFormErrors(['allowed_import_extensions']);
});
