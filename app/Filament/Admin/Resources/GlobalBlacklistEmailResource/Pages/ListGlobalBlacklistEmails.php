<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GlobalBlacklistEmailResource\Pages;

use App\Filament\Admin\Resources\GlobalBlacklistEmailResource;
use App\Jobs\Blacklist\ExportBlacklistCsvJob;
use App\Jobs\Blacklist\ImportBlacklistCsvJob;
use App\Models\GlobalBlacklistEmail;
use App\Services\Blacklist\BlacklistEntries;
use App\Services\Blacklist\BlacklistMatcher;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * §3.17 global blacklist index (WP-16): add / bulk paste / queued CSV
 * import / queued export / rule tester. Admin rows are install-wide
 * (customer_id null) so they bind every tenant in either isolation mode.
 */
class ListGlobalBlacklistEmails extends ListRecords
{
    protected static string $resource = GlobalBlacklistEmailResource::class;

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
                    $data['customer_id'] = null;
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
                        null,
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
                        ->directory('admin/imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    ImportBlacklistCsvJob::dispatch((string) Str::uuid(), (string) $data['file'], null);

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
                    ExportBlacklistCsvJob::dispatch((string) Str::uuid(), null);

                    Notification::make()
                        ->title(__('blacklist.notifications.export_queued'))
                        ->success()
                        ->send();
                }),

            Actions\Action::make('downloadExport')
                ->label(__('blacklist.actions.download_export'))
                ->icon('heroicon-o-document-arrow-down')
                ->authorize('viewAny', GlobalBlacklistEmail::class)
                ->url(fn (): string => route('admin.blacklist.export.download'))
                ->visible(fn (): bool => Storage::disk('local')->exists(ExportBlacklistCsvJob::ADMIN_PATH)),

            // §3.17 rule tester (F-3.17-005): exact rows + domain rows + the
            // BlacklistSettings regex rules through the one BlacklistMatcher.
            Actions\Action::make('testRules')
                ->label(__('blacklist.actions.test_rules'))
                ->icon('heroicon-o-beaker')
                ->modalHeading(__('blacklist.tester.heading'))
                ->authorize('viewAny', GlobalBlacklistEmail::class)
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label(__('blacklist.tester.email'))
                        ->helperText(__('blacklist.tester.help'))
                        ->email()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $match = app(BlacklistMatcher::class)->matchType((string) $data['email']);

                    $message = match ($match) {
                        BlacklistMatcher::MATCH_EXACT => __('blacklist.tester.match_exact'),
                        BlacklistMatcher::MATCH_DOMAIN => __('blacklist.tester.match_domain'),
                        BlacklistMatcher::MATCH_REGEX => __('blacklist.tester.match_regex'),
                        default => __('blacklist.tester.no_match'),
                    };

                    Notification::make()
                        ->title($message)
                        ->status($match === null ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }
}
