<?php

declare(strict_types=1);

namespace App\Filament\Customer\Pages;

use App\Filament\Admin\Pages\ThemePreferencePage;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\SkinSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Per-CUSTOMER theme selection (spec §6.3 F-6.3-002 — "each admin/customer
 * picks their own", WP-38).
 *
 * The customer-panel twin of {@see ThemePreferencePage},
 * saving `customers.theme`. Options are filtered to themes that declare
 * support for the customer area, so a package that only skins the admin
 * panel is never offered to a tenant who would then see it do nothing.
 */
class ThemePreference extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $slug = 'my-theme';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.customer.pages.theme-preference';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $customer = auth('customer')->user();

        $this->form->fill([
            'theme' => $customer === null ? null : app(ThemeResolver::class)->preferenceFor($customer),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('themes.preference.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('themes.preference.navigation_label');
    }

    public function form(Form $form): Form
    {
        $registry = app(ThemeRegistry::class);

        return $form->statePath('data')->schema([
            Forms\Components\Select::make('theme')
                ->label(__('themes.preference.field'))
                ->helperText(__('themes.preference.help'))
                ->options($registry->options(SkinSettings::AREA_CUSTOMER))
                ->placeholder(__('themes.preference.inherit'))
                ->in($registry->slugsForArea(SkinSettings::AREA_CUSTOMER)),
        ]);
    }

    public function save(): void
    {
        $customer = auth('customer')->user();

        if ($customer !== null) {
            app(ThemeResolver::class)->setPreference($customer, $this->form->getState()['theme'] ?? null);
        }

        Notification::make()->success()->title(__('themes.preference.saved'))->send();
    }
}
