<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\DomainStatus;
use App\Filament\Concerns\BuildsDomainResources;
use App\Filament\Customer\Resources\TrackingDomainResource\Pages;
use App\Models\TrackingDomain;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Customer tracking domains (spec §2.9.8, WP-15): own rows only, creation
 * gated by domains.tracking.add + max_tracking_domains
 * (TrackingDomainPolicy). Shows the required CNAME record (copyable),
 * verify action, SSL provisioning placeholder. Campaign assignment happens
 * on the campaign form (WP-11 select — live now the table exists).
 */
class TrackingDomainResource extends Resource
{
    use BuildsDomainResources;

    protected static ?string $model = TrackingDomain::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $slug = 'tracking-domains';

    protected static ?int $navigationSort = 62;

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
        return __('sending.resource.navigation_group');
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
                    Forms\Components\Placeholder::make('cname_hint')
                        ->label(__('infra.domains.cname_target'))
                        ->content(fn (): string => __('infra.help.cname_hint', ['target' => TrackingDomain::cnameTarget()])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::trackingDomainColumns())
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
            ->bulkActions([]);
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
