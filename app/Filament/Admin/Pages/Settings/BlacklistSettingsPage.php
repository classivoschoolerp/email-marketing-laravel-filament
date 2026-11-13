<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\BlacklistSettings;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Email blacklist settings page (spec §3.25.5, rows F-3.25.5-001 … 004).
 */
class BlacklistSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'settings/blacklist';

    protected static ?int $navigationSort = 5;

    protected static function settingsClass(): string
    {
        return BlacklistSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.blacklist.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.blacklist.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.blacklist.sections.general'))
                ->description(__('settings.blacklist.sections.general_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('settings.blacklist.fields.enabled'))
                        ->helperText(__('settings.blacklist.help.enabled')),
                    Select::make('apply_at')
                        ->label(__('settings.blacklist.fields.apply_at'))
                        ->options([
                            BlacklistSettings::APPLY_AT_IMPORT => __('settings.blacklist.options.import'),
                            BlacklistSettings::APPLY_AT_SEND => __('settings.blacklist.options.send'),
                            BlacklistSettings::APPLY_AT_BOTH => __('settings.blacklist.options.both'),
                        ])
                        ->in(BlacklistSettings::APPLY_AT)
                        ->required(),
                    Toggle::make('shared_across_customers')
                        ->label(__('settings.blacklist.fields.shared_across_customers'))
                        ->helperText(__('settings.blacklist.help.shared_across_customers'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('settings.blacklist.sections.regex'))
                ->description(__('settings.blacklist.sections.regex_description'))
                ->schema([
                    Repeater::make('regex_rules')
                        ->label(__('settings.blacklist.fields.regex_rules'))
                        ->simple(
                            TextInput::make('pattern')
                                ->label(__('settings.blacklist.fields.pattern'))
                                ->placeholder('/^spam-.*@example\.com$/i')
                                ->required()
                                ->maxLength(500)
                                ->rules([self::validRegexRule()]),
                        )
                        ->addActionLabel(__('settings.blacklist.actions.add_rule'))
                        ->reorderable(false)
                        ->default([]),
                ]),
        ];
    }

    /**
     * Validates that a value is a complete, compilable PCRE pattern.
     * (Wrapped so Filament's closure evaluation hands the inner closure to
     * the validator instead of trying to inject its parameters.)
     */
    protected static function validRegexRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || @preg_match($value, '') === false) {
                $fail(__('settings.blacklist.validation.invalid_regex'));
            }
        };
    }
}
