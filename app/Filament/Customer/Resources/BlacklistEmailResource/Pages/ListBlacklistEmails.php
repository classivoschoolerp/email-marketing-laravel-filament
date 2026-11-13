<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\BlacklistEmailResource\Pages;

use App\Filament\Customer\Resources\BlacklistEmailResource;
use App\Jobs\Blacklist\ExportBlacklistCsvJob;
use App\Jobs\Blacklist\ImportBlacklistCsvJob;
use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use App\Services\Blacklist\BlacklistEntries;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * §2.9.10 customer blacklist index: add single (or a whole domain), bulk
 * paste, queued CSV import/export, remove, search & filter. Every mutation
 * stamps the acting customer's id so isolation mode stays airtight.
 */
class ListBlacklistEmails extends ListRecords
{
    protected static string $resource = BlacklistEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('blacklist.actions.add'))
                ->modalHeading(__('blacklist.actions.add'))
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label(__('blacklist.form.email'))
                        ->required()
                        ->maxLength(191),
                    Forms\Components\Toggle::make('is_domain')
                        ->label(__('blacklist.form.is_domain'))
                        ->helperText(__('blacklist.form.is_domain_help'))
                        ->default(false),
                    Forms\Components\TextInput::make('reason')
                        ->label(__('blacklist.form.reason'))
                        ->maxLength(191),
                ])
                ->mutateFormDataUsing(function (array $data): array {
                    $data['customer_id'] = Filament::auth()->id();
                    $data['source'] = GlobalBlacklistEmail::SOURCE_MANUAL;

                    return $data;
                }),

            Actions\Action::make('bulkPaste')
                ->label(__('blacklist.actions.bulk_paste'))
                ->icon('heroicon-o-clipboard-document-list')
                ->authorize('create', GlobalBlacklistEmail::class)
                ->form([
                    Forms\Components\Textarea::make('emails')
                        ->label(__('blacklist.form.emails'))
                        ->helperText(__('blacklist.form.emails_help'))
                        ->rows(8)
                        ->required(),
                    Forms\Components\TextInput::make('reason')
                        ->label(__('blacklist.form.reason'))
                        ->maxLength(191),
                ])
                ->action(function (array $data): void {
                    $entries = app(BlacklistEntries::class);

                    $count = $entries->addMany(
                        $entries->parse((string) $data['emails']),
                        ($data['reason'] ?? '') !== '' ? (string) $data['reason'] : null,
                        GlobalBlacklistEmail::SOURCE_MANUAL,
                        (int) Filament::auth()->id(),
                    );

                    Notification::make()
                        ->title(__('blacklist.notifications.added', ['count' => $count]))
                        ->success()
                        ->send();
                }),

            Actions\Action::make('import')
                ->label(__('blacklist.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize('create', GlobalBlacklistEmail::class)
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label(__('blacklist.form.file'))
                        ->helperText(__('blacklist.form.file_help'))
                        ->disk('local')
                        ->directory('customers/'.$this->customerUid().'/imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    ImportBlacklistCsvJob::dispatch(
                        (string) Str::uuid(),
                        (string) $data['file'],
                        (int) Filament::auth()->id(),
                    );

                    Notification::make()
                        ->title(__('blacklist.notifications.import_queued'))
                        ->success()
                        ->send();
                }),

            Actions\Action::make('export')
                ->label(__('blacklist.actions.export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->authorize('viewAny', GlobalBlacklistEmail::class)
                ->action(function (): void {
                    ExportBlacklistCsvJob::dispatch((string) Str::uuid(), (int) Filament::auth()->id());

                    Notification::make()
                        ->title(__('blacklist.notifications.export_queued'))
                        ->success()
                        ->send();
                }),

            Actions\Action::make('downloadExport')
                ->label(__('blacklist.actions.download_export'))
                ->icon('heroicon-o-document-arrow-down')
                ->authorize('viewAny', GlobalBlacklistEmail::class)
                ->url(fn (): string => route('blacklist.export.download'))
                ->visible(fn (): bool => Storage::disk('local')
                    ->exists('customers/'.$this->customerUid().'/exports/blacklist.csv')),
        ];
    }

    private function customerUid(): string
    {
        $user = Filament::auth()->user();

        return $user instanceof Customer ? (string) $user->uid : 'unknown';
    }
}
