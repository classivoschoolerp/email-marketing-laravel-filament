<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Field;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Spatie\LaravelSettings\Settings;

/**
 * Shared shell for the §3.25 settings pages: loads a spatie settings class into
 * the form, validates on save, persists through the settings repository.
 */
abstract class BaseSettingsPage extends Page
{
    use InteractsWithFormActions;

    protected static string $view = 'filament.admin.pages.settings.settings-page';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    /**
     * @return class-string<Settings>
     */
    abstract protected static function settingsClass(): string;

    /**
     * @return array<int, Component>
     */
    abstract protected function schema(): array;

    public static function getNavigationGroup(): ?string
    {
        return __('settings.navigation_group');
    }

    public function mount(): void
    {
        $this->form->fill(app(static::settingsClass())->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->schema())
            ->statePath('data');
    }

    public function save(): void
    {
        $settings = app(static::settingsClass());

        $settings->fill($this->form->getState());
        $settings->save();

        Notification::make()
            ->success()
            ->title(__('settings.saved'))
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('settings.save'))
                ->submit('save'),
        ];
    }

    /**
     * Livewire submits every input as a string; cast back to the int the
     * strictly-typed settings property expects.
     */
    protected static function castInt(Field $component): Field
    {
        return $component->dehydrateStateUsing(fn (mixed $state): int => (int) $state);
    }

    protected static function castNullableInt(Field $component): Field
    {
        return $component->dehydrateStateUsing(
            fn (mixed $state): ?int => ($state === null || $state === '') ? null : (int) $state,
        );
    }

    protected static function castFloat(Field $component): Field
    {
        return $component->dehydrateStateUsing(fn (mixed $state): float => (float) $state);
    }

    /**
     * Empty text inputs arrive as '' — store NULL for nullable string settings.
     */
    protected static function castNullableString(Field $component): Field
    {
        return $component->dehydrateStateUsing(
            fn (mixed $state): ?string => ($state === null || $state === '') ? null : (string) $state,
        );
    }
}
