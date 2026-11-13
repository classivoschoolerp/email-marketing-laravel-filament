<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages;

use App\Enums\MonitorAction;
use App\Enums\MonitorConditionField;
use App\Enums\MonitorConditionOperator;
use App\Filament\Admin\Resources\EmailBoxMonitorResource;
use App\Models\EmailBoxMonitor;
use App\Models\EmailList;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Condition management for one monitor (spec §2.9.6 F-2.9.6-002/003/004):
 * subject / body / from matched by contains, not-contains or regex, each with
 * its own action and an explicit priority. Regex patterns are validated at
 * the form so a broken rule can never reach the processor.
 */
class EmailBoxMonitorConditions extends ManageRelatedRecords
{
    protected static string $resource = EmailBoxMonitorResource::class;

    protected static string $relationship = 'conditions';

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    public static function getNavigationLabel(): string
    {
        return __('infra.monitors.sections.conditions');
    }

    public function getTitle(): string|Htmlable
    {
        /** @var EmailBoxMonitor $monitor */
        $monitor = $this->getRecord();

        return __('infra.monitors.sections.conditions').' — '.$monitor->name;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('field')
                ->label(__('infra.monitors.fields.field'))
                ->options(MonitorConditionField::options())
                ->default(MonitorConditionField::Subject->value)
                ->required(),
            Forms\Components\Select::make('operator')
                ->label(__('infra.monitors.fields.operator'))
                ->options(MonitorConditionOperator::options())
                ->default(MonitorConditionOperator::Contains->value)
                ->live()
                ->required(),
            Forms\Components\TextInput::make('value')
                ->label(__('infra.monitors.fields.value'))
                ->helperText(__('infra.monitors.help.value'))
                ->maxLength(512)
                ->required()
                ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if ($get('operator') !== MonitorConditionOperator::Regex->value) {
                        return;
                    }

                    if (@preg_match((string) $value, '') === false) {
                        $fail(__('infra.monitors.errors.invalid_regex'));
                    }
                }),
            Forms\Components\Select::make('action')
                ->label(__('infra.monitors.fields.action'))
                ->options(MonitorAction::options())
                ->default(MonitorAction::Unsubscribe->value)
                ->live()
                ->required(),
            Forms\Components\Select::make('target_list_id')
                ->label(__('infra.monitors.fields.target_list'))
                ->helperText(__('infra.monitors.help.target_list'))
                ->options(fn (): array => EmailList::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('action') === MonitorAction::MoveToList->value)
                ->required(fn (Get $get): bool => $get('action') === MonitorAction::MoveToList->value),
            Forms\Components\TextInput::make('notify_email')
                ->label(__('infra.monitors.fields.notify_email'))
                ->helperText(__('infra.monitors.help.notify_email'))
                ->email()
                ->visible(fn (Get $get): bool => $get('action') === MonitorAction::NotifyAdmin->value),
            Forms\Components\TextInput::make('priority')
                ->label(__('infra.monitors.fields.priority'))
                ->helperText(__('infra.monitors.help.priority'))
                ->numeric()->integer()->minValue(0)
                ->default(10)
                ->required(),
            Forms\Components\Toggle::make('enabled')
                ->label(__('infra.monitors.fields.enabled'))
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('infra.monitors.fields.priority'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('field')
                    ->label(__('infra.monitors.fields.field'))
                    ->formatStateUsing(fn (MonitorConditionField $state): string => $state->label()),
                Tables\Columns\TextColumn::make('operator')
                    ->label(__('infra.monitors.fields.operator'))
                    ->formatStateUsing(fn (MonitorConditionOperator $state): string => $state->label()),
                Tables\Columns\TextColumn::make('value')
                    ->label(__('infra.monitors.fields.value'))
                    ->limit(60),
                Tables\Columns\TextColumn::make('action')
                    ->label(__('infra.monitors.fields.action'))
                    ->badge()
                    ->formatStateUsing(fn (MonitorAction $state): string => $state->label()),
                Tables\Columns\IconColumn::make('enabled')
                    ->label(__('infra.monitors.fields.enabled'))
                    ->boolean(),
            ])
            ->defaultSort('priority')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->paginated([25, 50]);
    }
}
