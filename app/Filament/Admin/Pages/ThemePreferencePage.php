<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

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
 * Per-admin-user theme selection (spec §6.3 F-6.3-002, WP-38).
 *
 * Mirrors {@see LocalePreferencePage} exactly — a small dedicated page
 * reached from the user menu, saving one column on the user's own row. It is
 * NOT permission-gated: choosing your own colour scheme is a preference, not
 * an administrative act, so every admin has it while only `themes.manage`
 * holders can change what everyone else defaults to.
 *
 * The blank option CLEARS the column rather than storing "default", so an
 * operator who later re-brands the installation still reaches this user.
 */
class ThemePreferencePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';


    protected static ?string $slug = 'my-theme';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return __('themes.nav_group');
    }

    protected static string $view = 'filament.admin.pages.theme-preference';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $user = auth('admin')->user();

        $this->form->fill([
            'theme' => $user === null ? null : app(ThemeResolver::class)->preferenceFor($user),
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
                ->options($registry->options(SkinSettings::AREA_BACKEND))
                ->placeholder(__('themes.preference.inherit'))
                ->in($registry->slugsForArea(SkinSettings::AREA_BACKEND)),
        ]);
    }

    public function save(): void
    {
        $user = auth('admin')->user();

        if ($user !== null) {
            app(ThemeResolver::class)->setPreference($user, $this->form->getState()['theme'] ?? null);
        }

        Notification::make()->success()->title(__('themes.preference.saved'))->send();
    }
}
