<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\DomainStatus;
use App\Filament\Concerns\BuildsDomainResources;
use App\Filament\Customer\Resources\SendingDomainResource\Pages;
use App\Models\SendingDomain;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Customer sending domains (spec §2.9.7, WP-15): own rows only (tenancy via
 * the global scope), creation gated by the domains.sending.add permission +
 * max_sending_domains quota (SendingDomainPolicy / D-42 matrix keys). DKIM
 * keys are generated on create; the view page shows the DNS records with
 * copy-to-clipboard and the one-click verify.
 */
class SendingDomainResource extends Resource
{
    use BuildsDomainResources;

    protected static ?string $model = SendingDomain::class;

    protected static ?string $navigationIcon = 'heroicon-o-at-symbol';

    protected static ?string $slug = 'sending-domains';

    protected static ?int $navigationSort = 61;

    public static function getModelLabel(): string
    {
        return __('infra.domains.sending_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infra.domains.sending_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('infra.domains.sending_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sending.resource.navigation_group');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('infra.domains.sending_label'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('infra.domains.domain'))
                        ->helperText(__('infra.help.sending_domain_name'))
                        ->required()
                        ->maxLength(255)
                        ->regex('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i'),
                    Forms\Components\TextInput::make('dkim_selector')
                        ->label(__('infra.domains.dkim_selector'))
                        ->helperText(__('infra.help.dkim_selector'))
                        ->default(fn (): string => (string) config('emp-infra.domains.dkim_selector', 'default'))
                        ->maxLength(64)
                        ->regex('/^[a-z0-9_-]+$/i')
                        ->required(),
                ]),

            // F-2.9.7-009 — the two URLs the BIMI record is built from.
            Forms\Components\Section::make(__('deliverability.bimi.section'))
                ->description(__('deliverability.bimi.description'))
                ->columns(2)
                ->collapsed()
                ->visible(fn (): bool => static::bimiAvailable())
                ->schema(static::bimiFormFields()),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return static::sendingDomainInfolist($infolist);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(static::sendingDomainColumns())
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('infra.fields.status'))
                    ->options(DomainStatus::options()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::verifySendingDomainAction(Tables\Actions\Action::class),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSendingDomains::route('/'),
            'create' => Pages\CreateSendingDomain::route('/create'),
            'view' => Pages\ViewSendingDomain::route('/{record}'),
            'edit' => Pages\EditSendingDomain::route('/{record}/edit'),
        ];
    }
}
