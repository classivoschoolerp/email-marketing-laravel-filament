<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\DomainStatus;
use App\Filament\Admin\Resources\TrackingDomainResource\Pages;
use App\Filament\Concerns\BuildsDomainResources;
use App\Models\Customer;
use App\Models\Scopes\CustomerScope;
use App\Models\TrackingDomain;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin tracking domains (spec §2.9.8 + §3.13.6 global with verify-all,
 * WP-15): CNAME record display (copyable), DNS verify, SSL provisioning
 * placeholder badge. Assignment to servers happens on the delivery-server
 * form; to campaigns via the WP-11 campaign select (live now that
 * tracking_domains exists). Guarded by TrackingDomainPolicy (domains.manage).
 */
class TrackingDomainResource extends Resource
{
    use BuildsDomainResources;

    protected static ?string $model = TrackingDomain::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static ?string $slug = 'tracking-domains';

    protected static ?int $navigationSort = 76;

    public static function getModelLabel(): string
    {
        return __('infra.domains.tracking_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infra.domains.tracking_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('infra.domains.tracking_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('infra.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(CustomerScope::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('infra.domains.tracking_label'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('infra.domains.domain'))
                        ->helperText(__('infra.help.tracking_domain_name'))
                        ->required()
                        ->maxLength(255)
                        ->regex('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i'),
                    Forms\Components\Select::make('customer_id')
                        ->label(__('infra.fields.customer'))
                        ->helperText(__('infra.help.domain_customer'))
                        ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                        ->searchable()
                        ->nullable(),
                    Forms\Components\Placeholder::make('cname_hint')
                        ->label(__('infra.domains.cname_target'))
                        ->content(fn (): string => __('infra.help.cname_hint', ['target' => TrackingDomain::cnameTarget()]))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('infra.fields.customer'))
                    ->placeholder(__('infra.labels.system')),
                ...static::trackingDomainColumns(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('infra.fields.status'))
                    ->options(DomainStatus::options()),
            ])
            ->actions([
                static::verifyTrackingDomainAction(Tables\Actions\Action::class),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrackingDomains::route('/'),
            'create' => Pages\CreateTrackingDomain::route('/create'),
            'edit' => Pages\EditTrackingDomain::route('/{record}/edit'),
        ];
    }
}
