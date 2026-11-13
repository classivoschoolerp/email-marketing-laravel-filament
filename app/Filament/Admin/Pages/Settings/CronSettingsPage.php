<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\CronSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rule;

/**
 * Cron settings page (spec §3.25.11, rows F-3.25.11-001/002/004).
 * Run history (F-3.25.11-003) lives on {@see CronRunHistoryPage}.
 */
class CronSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'System Health';

    protected static ?string $slug = 'settings/cron';

    protected static ?int $navigationSort = 11;

    protected static function settingsClass(): string
    {
        return CronSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.cron.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.cron.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.cron.sections.batches'))
                ->description(__('settings.cron.sections.batches_description'))
                ->columns(3)
                ->schema([
                    self::castInt(
                        TextInput::make('emails_per_batch')
                            ->label(__('settings.cron.fields.emails_per_batch'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('subscribers_per_batch')
                            ->label(__('settings.cron.fields.subscribers_per_batch'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('parallel_processes')
                            ->label(__('settings.cron.fields.parallel_processes'))
                            ->helperText(__('settings.cron.help.parallel_processes'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(64)
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.cron.sections.concurrency'))
                ->description(__('settings.cron.sections.concurrency_description'))
                ->columns(2)
                ->schema([
                    self::castInt(
                        TextInput::make('delivery_concurrency')
                            ->label(__('settings.cron.fields.delivery_concurrency'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(64)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('bounce_concurrency')
                            ->label(__('settings.cron.fields.bounce_concurrency'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(64)
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.cron.sections.jobs'))
                ->description(__('settings.cron.sections.jobs_description'))
                ->schema([
                    CheckboxList::make('enabled_jobs')
                        ->label(__('settings.cron.fields.enabled_jobs'))
                        ->options(self::jobOptions())
                        ->nestedRecursiveRules([Rule::in(CronSettings::KNOWN_JOBS)])
                        ->columns(2)
                        ->bulkToggleable(),
                ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function jobOptions(): array
    {
        return collect(CronSettings::KNOWN_JOBS)
            ->mapWithKeys(fn (string $job): array => [$job => $job])
            ->all();
    }
}
