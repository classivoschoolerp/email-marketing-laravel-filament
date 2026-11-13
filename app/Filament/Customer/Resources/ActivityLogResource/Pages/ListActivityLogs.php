<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\ActivityLogResource\Pages;

use App\Filament\Customer\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §2.15.7 activity index + F-2.15.7-003 export.
 *
 * The export honours whatever the table is currently filtered to — the
 * exported file is what the customer is looking at, not a different
 * dataset. Streamed row by row so a long history never has to fit in
 * memory.
 */
class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    /** Hard ceiling so a runaway export cannot pin a worker. */
    public const EXPORT_LIMIT = 50_000;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label(__('activity.actions.export'))
                ->icon('heroicon-m-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    protected function exportCsv(): StreamedResponse
    {
        // getFilteredSortedTableQuery() is the live table query: filters,
        // search and sort exactly as displayed.
        $query = $this->getFilteredSortedTableQuery()->limit(self::EXPORT_LIMIT);

        $filename = 'activity-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                __('activity.fields.when'),
                __('activity.fields.actor'),
                __('activity.fields.action'),
                __('activity.fields.subject'),
                __('activity.fields.description'),
                __('activity.fields.ip'),
            ]);

            $query->chunk(500, function ($rows) use ($handle): void {
                /** @var ActivityLog $row */
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->created_at?->toDateTimeString() ?? '',
                        $row->actorLabel(),
                        $row->actionLabel(),
                        (string) ($row->subject_label ?? ''),
                        (string) ($row->description ?? ''),
                        (string) ($row->ip ?? ''),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
