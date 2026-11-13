<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Filament\Admin\Resources\CampaignOverviewResource\Pages;
use App\Jobs\Campaigns\BulkCampaignActionJob;
use App\Mail\CampaignApprovedMail;
use App\Mail\CampaignBlockedMail;
use App\Mail\CampaignChangesRequestedMail;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Scopes\CustomerScope;
use App\Services\Campaigns\BlacklistedWordScanner;
use App\Services\Campaigns\CampaignStatusMachine;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Campaigns overview across ALL customers (spec §3.7, WP-18): global index
 * with the customer column and type tabs, per-campaign admin report,
 * approve / block / pause / resume / delete through the WP-11/12 status
 * machine (D-57 — nothing outside the machine flips statuses), D-28 bulk
 * pause/block, filters by status/customer/date/list/delivery server, and
 * the flagged-content review tab (§3.25.3 blacklisted words, flag mode).
 * Approve/block queue the §3.15 F-3.15-007 mails. Gated by CampaignPolicy
 * (`campaigns.manage`).
 */
class CampaignOverviewResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Customer Activity';

    protected static ?string $slug = 'campaigns-overview';

    protected static ?int $navigationSort = 72;

    public static function getNavigationGroup(): ?string
    {
        return __('dashboards.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboards.campaigns_overview.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('dashboards.campaigns_overview.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('dashboards.campaigns_overview.model_label_plural');
    }

    public static function getEloquentQuery(): Builder
    {
        // Cross-tenant on purpose (§3.7); scope dropped explicitly even
        // though the admin panel binds no tenant — defense in depth.
        return parent::getEloquentQuery()
            ->withoutGlobalScope(CustomerScope::class)
            ->with(['customer', 'list']);
    }

    public static function table(Table $table): Table
    {
        $machine = app(CampaignStatusMachine::class);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('dashboards.campaigns_overview.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('dashboards.campaigns_overview.columns.customer'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('dashboards.campaigns_overview.columns.type'))
                    ->badge()
                    ->formatStateUsing(fn (CampaignType $state): string => $state->label())
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('dashboards.campaigns_overview.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (CampaignStatus $state): string => $state->label())
                    ->color(fn (CampaignStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('dashboards.campaigns_overview.columns.list'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('delivery_sent')
                    ->label(__('dashboards.campaigns_overview.columns.delivered'))
                    ->state(fn (Campaign $record): string => (int) $record->delivery_total > 0
                        ? number_format((int) $record->delivery_sent).' / '.number_format((int) $record->delivery_total)
                        : '—'),
                Tables\Columns\TextColumn::make('send_at')
                    ->label(__('dashboards.campaigns_overview.columns.send_at'))
                    ->state(fn (Campaign $record) => $record->send_at ?? $record->next_run_at)
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('dashboards.campaigns_overview.columns.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('dashboards.campaigns_overview.filters.status'))
                    ->multiple()
                    ->options(CampaignStatus::options()),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('dashboards.campaigns_overview.filters.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('email_list_id')
                    ->label(__('dashboards.campaigns_overview.filters.list'))
                    ->options(fn (): array => EmailList::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('delivery_server')
                    ->label(__('dashboards.campaigns_overview.filters.delivery_server'))
                    ->options(fn (): array => DeliveryServer::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $serverId): Builder => $q
                            ->whereJsonContains('delivery_server_ids', (int) $serverId))),
                Tables\Filters\Filter::make('created')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('dashboards.campaigns_overview.filters.created_from')),
                        Forms\Components\DatePicker::make('until')->label(__('dashboards.campaigns_overview.filters.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\Action::make('report')
                    ->label(__('dashboards.campaigns_overview.actions.report'))
                    ->icon('heroicon-m-chart-bar')
                    ->url(fn (Campaign $record): string => static::getUrl('report', ['record' => $record])),
                static::approveAction(),
                static::requestChangesAction(),
                static::blockAction(),
                static::pauseAction($machine),
                static::resumeAction($machine),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                static::bulkApproveAction(),
                static::bulkPauseAction($machine),
                static::bulkBlockAction($machine),
            ])
            ->paginated([25, 50, 100]);
    }

    // ---------------------------------------- status actions (F-3.7-003)

    /** Approve: pending-approval → scheduled + F-3.15-007 approved mail. */
    public static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label(__('dashboards.campaigns_overview.actions.approve'))
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::PendingApproval
                && (auth('admin')->user()?->can('update', $record) ?? false))
            ->action(function (Campaign $record): void {
                app(CampaignStatusMachine::class)->transition($record, CampaignStatus::Scheduled);

                static::notifyCustomer($record, new CampaignApprovedMail((string) $record->name));

                Notification::make()->success()
                    ->title(__('dashboards.campaigns_overview.notifications.approved'))
                    ->send();
            });
    }

    // -------------------------------------- approval queue (F-3.7-004)

    /**
     * Campaigns waiting for an admin decision. The queue exists because the
     * §3.25.3 "approval required" switch routes every scheduled campaign into
     * `pending-approval` (CampaignScheduler) — without a screen that lists
     * them by that status alone, an operator has to notice them by chance in
     * the all-campaigns table.
     */
    public static function pendingApprovalQuery(): Builder
    {
        return static::getEloquentQuery()->where('status', CampaignStatus::PendingApproval->value);
    }

    public static function pendingApprovalCount(): int
    {
        return static::pendingApprovalQuery()->count();
    }

    /**
     * Send back for edits: pending-approval → draft with a required reason.
     *
     * Deliberately NOT "block". Blocked is terminal and tells the customer
     * their campaign was refused; this is the reviewer saying "fix this and
     * resubmit", which is the transition the status machine already allows.
     */
    public static function requestChangesAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('requestChanges')
            ->label(__('dashboards.campaigns_overview.actions.request_changes'))
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('dashboards.campaigns_overview.actions.changes_reason'))
                    ->rows(3)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::PendingApproval
                && (auth('admin')->user()?->can('update', $record) ?? false))
            ->action(function (Campaign $record, array $data): void {
                app(CampaignStatusMachine::class)->transition($record, CampaignStatus::Draft);

                static::notifyCustomer($record, new CampaignChangesRequestedMail(
                    (string) $record->name,
                    (string) $data['reason'],
                ));

                Notification::make()->success()
                    ->title(__('dashboards.campaigns_overview.notifications.changes_requested'))
                    ->send();
            });
    }

    /** Bulk approve from the queue — D-28 filtering, then queued (rule 5). */
    public static function bulkApproveAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('bulkApprove')
            ->label(__('dashboards.campaigns_overview.actions.bulk_approve'))
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                static::queueBulk(
                    BulkCampaignActionJob::ACTION_APPROVE,
                    $records->filter(fn (Campaign $record): bool => $record->status === CampaignStatus::PendingApproval
                        && (auth('admin')->user()?->can('update', $record) ?? false)),
                    notifyCustomer: true,
                );
            });
    }

    /** Block from any active state + F-3.15-007 blocked mail (w/ reason). */
    public static function blockAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('block')
            ->label(__('dashboards.campaigns_overview.actions.block'))
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('dashboards.campaigns_overview.actions.block_reason'))
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->visible(fn (Campaign $record): bool => app(CampaignStatusMachine::class)
                ->canTransition($record->status, CampaignStatus::Blocked)
                && (auth('admin')->user()?->can('update', $record) ?? false))
            ->action(function (Campaign $record, array $data): void {
                app(CampaignStatusMachine::class)->transition($record, CampaignStatus::Blocked);

                static::notifyCustomer($record, new CampaignBlockedMail(
                    (string) $record->name,
                    ($data['reason'] ?? null) !== null && trim((string) $data['reason']) !== ''
                        ? (string) $data['reason']
                        : null,
                ));

                Notification::make()->success()
                    ->title(__('dashboards.campaigns_overview.notifications.blocked'))
                    ->send();
            });
    }

    public static function pauseAction(CampaignStatusMachine $machine): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('pause')
            ->label(__('dashboards.campaigns_overview.actions.pause'))
            ->icon('heroicon-m-pause')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Campaign $record): bool => $machine->canPause($record)
                && (auth('admin')->user()?->can('update', $record) ?? false))
            ->action(function (Campaign $record) use ($machine): void {
                $machine->transition($record, CampaignStatus::Paused);
                Notification::make()->success()
                    ->title(__('dashboards.campaigns_overview.notifications.paused'))
                    ->send();
            });
    }

    public static function resumeAction(CampaignStatusMachine $machine): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resume')
            ->label(__('dashboards.campaigns_overview.actions.resume'))
            ->icon('heroicon-m-play')
            ->color('success')
            ->visible(fn (Campaign $record): bool => $machine->canResume($record)
                && (auth('admin')->user()?->can('update', $record) ?? false))
            ->action(function (Campaign $record) use ($machine): void {
                $machine->resume($record);
                Notification::make()->success()
                    ->title(__('dashboards.campaigns_overview.notifications.resumed'))
                    ->send();
            });
    }

    // ------------------------------------------ bulk actions (F-3.7-006, D-28)

    public static function bulkPauseAction(CampaignStatusMachine $machine): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('bulkPause')
            ->label(__('dashboards.campaigns_overview.actions.bulk_pause'))
            ->icon('heroicon-m-pause')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($machine): void {
                // D-28: bulk actions run no per-record policies — filter out
                // rows the actor can't mutate or whose status forbids it,
                // then QUEUE the flips (F-Phase-3-Differentiat-006).
                static::queueBulk(
                    BulkCampaignActionJob::ACTION_PAUSE,
                    $records->filter(fn (Campaign $record): bool => $machine->canPause($record)
                        && (auth('admin')->user()?->can('update', $record) ?? false)),
                );
            });
    }

    public static function bulkBlockAction(CampaignStatusMachine $machine): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('bulkBlock')
            ->label(__('dashboards.campaigns_overview.actions.bulk_block'))
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->form([
                Forms\Components\Textarea::make('reason')
                    ->label(__('dashboards.campaigns_overview.actions.block_reason'))
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (Collection $records, array $data) use ($machine): void {
                // D-28 filtering, then the same blocked mail as the row action
                // — sent from the queued job (F-Phase-3-Differentiat-006).
                static::queueBulk(
                    BulkCampaignActionJob::ACTION_BLOCK,
                    $records->filter(fn (Campaign $record): bool => $machine->canTransition($record->status, CampaignStatus::Blocked)
                        && (auth('admin')->user()?->can('update', $record) ?? false)),
                    blockReason: ($data['reason'] ?? null) !== null && trim((string) $data['reason']) !== ''
                        ? (string) $data['reason']
                        : null,
                    notifyCustomer: true,
                );
            });
    }

    /**
     * Hand an already-authorized selection to the queue. The admin overview
     * spans tenants, so the job runs with NO customer context (customerId
     * null) and resolves rows withoutTenancy.
     *
     * @param  Collection<int, Campaign>  $records
     */
    private static function queueBulk(
        string $action,
        Collection $records,
        ?string $blockReason = null,
        bool $notifyCustomer = false,
    ): void {
        $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        if ($ids !== []) {
            BulkCampaignActionJob::dispatch(
                $action,
                $ids,
                null,
                (string) Str::uuid(),
                $blockReason,
                $notifyCustomer,
            );
        }

        Notification::make()->success()
            ->title(__('campaigns.notifications.bulk_queued', ['count' => count($ids)]))
            ->send();
    }

    // ------------------------------------- flagged content (F-3.7-007)

    /**
     * Campaign ids whose stored subject/content hit the §3.25.3 blacklisted
     * words — scanned on demand against the current word lists. In block
     * mode scheduling already refuses these; the review tab exists for flag
     * mode, where sending proceeds and the admin reviews.
     *
     * @return list<int>
     */
    public static function flaggedCampaignIds(): array
    {
        $scanner = app(BlacklistedWordScanner::class);

        return Campaign::withoutTenancy()
            ->get(['id', 'subject', 'content_html'])
            ->filter(fn (Campaign $campaign): bool => $scanner->subjectMatches($campaign->subject) !== []
                || $scanner->contentMatches($campaign->content_html) !== [])
            ->pluck('id')
            ->values()
            ->all();
    }

    private static function notifyCustomer(
        Campaign $record,
        CampaignApprovedMail|CampaignBlockedMail|CampaignChangesRequestedMail $mail,
    ): void {
        $customer = $record->customer()->withoutGlobalScopes()->first();

        if ($customer?->email !== null) {
            Mail::to((string) $customer->email)->queue($mail);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaignOverviews::route('/'),
            'report' => Pages\CampaignAdminReport::route('/{record}/report'),
        ];
    }
}
