<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\DomainStatus;
use App\Filament\Admin\Resources\SendingDomainResource\Pages;
use App\Filament\Concerns\BuildsDomainResources;
use App\Models\Customer;
use App\Models\Scopes\CustomerScope;
use App\Models\SendingDomain;
use App\Services\Domains\SyncDkimToServers;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin sending domains (spec §2.9.7 + §3.13.5 global with verify-all,
 * WP-15). Creation auto-generates the DKIM RSA-2048 keypair
 * (GenerateDkimKeypair, D-37); the view page shows the required DNS records
 * with copy-to-clipboard and per-record pass/fail. Guarded by
 * SendingDomainPolicy (domains.manage).
 */
class SendingDomainResource extends Resource
{
    use BuildsDomainResources;

    protected static ?string $model = SendingDomain::class;

    protected static ?string $navigationIcon = 'heroicon-o-at-symbol';

    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static ?string $slug = 'sending-domains';

    protected static ?int $navigationSort = 75;

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
        return __('infra.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(CustomerScope::class);
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
                    Forms\Components\Select::make('customer_id')
                        ->label(__('infra.fields.customer'))
                        ->helperText(__('infra.help.domain_customer'))
                        ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                        ->searchable()
                        ->nullable(),
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
            ->columns([
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('infra.fields.customer'))
                    ->placeholder(__('infra.labels.system')),
                ...static::sendingDomainColumns(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('infra.fields.status'))
                    ->options(DomainStatus::options()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::verifySendingDomainAction(Tables\Actions\Action::class),
                Tables\Actions\Action::make('sync_dkim')
                    ->label(__('infra.actions.sync_dkim'))
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->modalDescription(__('infra.help.sync_dkim'))
                    ->action(function (SendingDomain $record): void {
                        $count = app(SyncDkimToServers::class)->syncLinkedServers($record);

                        Notification::make()
                            ->title(__('infra.notifications.dkim_synced', ['count' => $count]))
                            ->success()
                            ->send();
                    }),
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
            'index' => Pages\ListSendingDomains::route('/'),
            'create' => Pages\CreateSendingDomain::route('/create'),
            'view' => Pages\ViewSendingDomain::route('/{record}'),
            'edit' => Pages\EditSendingDomain::route('/{record}/edit'),
        ];
    }
}
