<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Enums\CampaignStatus;
use App\Enums\ExportFormat;
use App\Models\Campaign;
use App\Services\Reports\CampaignReportExporter;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Widgets\TableWidget;

/**
 * Shared shell for the §2.3.8 report tab tables (WP-17): campaign record,
 * queued CSV export header action (F-2.3.8-018) and auto-polling while the
 * campaign is sending (F-2.3.8-021). Each subclass reads its rows through
 * CampaignReportService — never ad-hoc queries.
 */
abstract class ReportTableWidget extends TableWidget
{
    public ?Campaign $record = null;

    protected int|string|array $columnSpan = 'full';

    /** CampaignReportService::TABS key for the CSV export. */
    abstract protected function tab(): string;

    public function getCampaign(): Campaign
    {
        /** @var Campaign $record */
        $record = $this->record;

        return $record;
    }

    /**
     * F-2.3.8-018 (WP-26): CSV/XLSX chooser, CSV preselected. One place —
     * every report tab widget inherits it.
     */
    protected function exportAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('export_csv')
            ->label(__('reports.actions.export_csv'))
            ->icon('heroicon-m-arrow-down-tray')
            ->form([
                Radio::make('format')
                    ->label(__('reports.export.format'))
                    ->options(ExportFormat::options())
                    ->default(ExportFormat::Csv->value)
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $data): void {
                app(CampaignReportExporter::class)->queue(
                    $this->getCampaign(),
                    $this->tab(),
                    $data['format'] ?? null,
                );

                Notification::make()->success()
                    ->title(__('reports.notifications.export_queued'))
                    ->send();
            });
    }

    protected function pollInterval(): ?string
    {
        return $this->record?->status === CampaignStatus::Sending ? '10s' : null;
    }
}
