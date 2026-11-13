<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\I18n\Locales;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Per-admin-user language selection (spec §6.5 F-6.5-005, WP-20): a small
 * dedicated page reached from the user menu — the admin Security/account
 * page belongs to WP-03 and stays untouched. Saves users.locale, which
 * {@see \App\Http\Middleware\SetLocale} applies on every panel request.
 */
class LocalePreferencePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $slug = 'my-locale';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.navigation.location');
    }

    protected static string $view = 'filament.admin.pages.locale-preference';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'locale' => (string) (auth('admin')->user()?->locale ?? 'en'),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('i18n.locale_page.title');
    }

    public function form(Form $form): Form
    {
        $options = Locales::enabledOptions();

        // Pre-seed fallback so the page works before any pack is installed.
        if ($options === []) {
            $options = ['en' => 'English'];
        }

        return $form->statePath('data')->schema([
            Forms\Components\Select::make('locale')
                ->label(__('i18n.locale_page.language'))
                ->options($options)
                ->in(array_keys($options))
                ->required(),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        auth('admin')->user()?->forceFill(['locale' => (string) $state['locale']])->save();

        Notification::make()->title(__('i18n.locale_page.saved'))->success()->send();
    }
}
