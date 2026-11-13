<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Filament\Customer\Resources\SuppressionListResource\Pages;
use App\Jobs\Suppression\ExportSuppressionListJob;
use App\Jobs\Suppression\ImportSuppressionCsvJob;
use App\Jobs\Suppression\ImportSuppressionFromListJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\SuppressionList;
use App\Services\Suppression\SuppressionListEmails;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Suppression lists (spec §2.9.11, WP-16): named per-customer lists whose
 * addresses are subtracted from campaign audiences via
 * campaigns.suppression_list_ids + CampaignRecipients. Gated by the D-42
 * group key `suppression.manage` (SuppressionListPolicy). Add manually /
 * queued CSV import / queued import-from-list / queued export; the
 * emails_count column is maintained by SuppressionListEmails
 * (F-2.9.11-005). Domain-level rows (F-2.9.11-003) are P2 — not modelled.
 */
class SuppressionListResource extends Resource
{
    protected static ?string $model = SuppressionList::class;

    protected static ?string $navigationIcon = 'heroicon-o-eye-slash';

    protected static ?string $slug = 'suppression-lists';

    protected static ?int $navigationSort = 61;

    public static function getModelLabel(): string
    {
        return __('blacklist.suppression.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('blacklist.suppression.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('blacklist.suppression.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.nav_group');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('suppression_lists.customer_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('blacklist.suppression.name'))
                ->required()
                ->maxLength(191),
            Forms\Components\TextInput::make('description')
                ->label(__('blacklist.suppression.description'))
                ->maxLength(191),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('blacklist.suppression.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('blacklist.suppression.description'))
                    ->limit(50)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('emails_count')
                    ->label(__('blacklist.suppression.emails_count'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('blacklist.suppression.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('addEmails')
                    ->label(__('blacklist.suppression.add_emails'))
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\Textarea::make('emails')
                            ->label(__('blacklist.suppression.emails'))
                            ->helperText(__('blacklist.suppression.emails_help'))
                            ->rows(8)
                            ->required(),
                    ])
                    ->action(function (SuppressionList $record, array $data): void {
                        $service = app(SuppressionListEmails::class);
                        $count = $service->addMany($record, $service->parse((string) $data['emails']));

                        Notification::make()
                            ->title(__('blacklist.suppression.added', ['count' => $count]))
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('importCsv')
                    ->label(__('blacklist.suppression.import_csv'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label(__('blacklist.form.file'))
                            ->disk('local')
                            ->directory(fn (SuppressionList $record): string => 'customers/'.static::customerUid().'/imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->required(),
                    ])
                    ->action(function (SuppressionList $record, array $data): void {
                        ImportSuppressionCsvJob::dispatch((string) Str::uuid(), (int) $record->id, (string) $data['file']);

                        Notification::make()
                            ->title(__('blacklist.suppression.import_queued'))
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('importFromList')
                    ->label(__('blacklist.suppression.import_from_list'))
                    ->icon('heroicon-o-queue-list')
                    ->form([
                        Forms\Components\Select::make('email_list_id')
                            ->label(__('blacklist.suppression.source_list'))
                            ->options(fn (): array => EmailList::query()->pluck('name', 'id')->all())
                            ->required(),
                    ])
                    ->action(function (SuppressionList $record, array $data): void {
                        ImportSuppressionFromListJob::dispatch(
                            (string) Str::uuid(),
                            (int) $record->id,
                            (int) $data['email_list_id'],
                        );

                        Notification::make()
                            ->title(__('blacklist.suppression.from_list_queued'))
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('export')
                    ->label(__('blacklist.suppression.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (SuppressionList $record): void {
                        ExportSuppressionListJob::dispatch((string) Str::uuid(), (int) $record->id);

                        Notification::make()
                            ->title(__('blacklist.suppression.export_queued'))
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('downloadExport')
                    ->label(__('blacklist.suppression.download_export'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (SuppressionList $record): string => route('suppression.export.download', ['uid' => $record->uid]))
                    ->visible(fn (SuppressionList $record): bool => Storage::disk('local')
                        ->exists(ExportSuppressionListJob::pathFor($record))),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                // D-28: the tenancy fence already limits records to the
                // acting customer; re-filter anyway so a policy change can
                // never widen the bulk surface.
                Tables\Actions\DeleteBulkAction::make()
                    ->using(function (Collection $records): void {
                        $user = Filament::auth()->user();

                        $records
                            ->filter(fn (SuppressionList $record): bool => $user instanceof Customer
                                && $record->customer_id === $user->id)
                            ->each(fn (SuppressionList $record) => $record->delete());
                    }),
            ]);
    }

    public static function customerUid(): string
    {
        $user = Filament::auth()->user();

        return $user instanceof Customer ? (string) $user->uid : 'unknown';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppressionLists::route('/'),
            'create' => Pages\CreateSuppressionList::route('/create'),
            'edit' => Pages\EditSuppressionList::route('/{record}/edit'),
        ];
    }
}
