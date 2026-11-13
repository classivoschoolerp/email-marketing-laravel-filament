<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeliveryServerGroupResource\Pages;

use App\Filament\Admin\Resources\DeliveryServerGroupResource;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * IP pool management for dedicated IPs (spec §3.13.1 F-3.13.1-009, WP-33):
 * the addresses a pool's member servers send from, with their PTR record and
 * the server each one is bound to. These are exactly the targets the
 * §2.9.9 DNSBL monitor checks.
 */
class DeliveryServerGroupIps extends ManageRelatedRecords
{
    protected static string $resource = DeliveryServerGroupResource::class;

    protected static string $relationship = 'ips';

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    public static function getNavigationLabel(): string
    {
        return __('sending.groups.sections.ips');
    }

    public function getTitle(): string|Htmlable
    {
        /** @var DeliveryServerGroup $group */
        $group = $this->getRecord();

        return __('sending.groups.sections.ips').' — '.$group->name;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('ip_address')
                ->label(__('sending.groups.fields.ip_address'))
                ->helperText(__('sending.groups.help.ip_address'))
                ->required()
                ->maxLength(45)
                ->rule('ip')
                ->validationMessages(['ip' => __('sending.groups.errors.invalid_ip')]),
            Forms\Components\TextInput::make('ptr_record')
                ->label(__('sending.groups.fields.ptr_record'))
                ->maxLength(255),
            Forms\Components\Select::make('delivery_server_id')
                ->label(__('sending.groups.fields.server'))
                ->options(fn (): array => DeliveryServer::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->nullable(),
            Forms\Components\Toggle::make('dedicated')
                ->label(__('sending.groups.fields.dedicated'))
                ->default(true),
            Forms\Components\Toggle::make('enabled')
                ->label(__('sending.groups.fields.enabled'))
                ->default(true),
            Forms\Components\Textarea::make('notes')
                ->label(__('sending.groups.fields.notes'))
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ip_address')
                    ->label(__('sending.groups.fields.ip_address'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ptr_record')
                    ->label(__('sending.groups.fields.ptr_record'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('server.name')
                    ->label(__('sending.groups.fields.server'))
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('dedicated')
                    ->label(__('sending.groups.fields.dedicated'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('enabled')
                    ->label(__('sending.groups.fields.enabled'))
                    ->boolean(),
            ])
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
