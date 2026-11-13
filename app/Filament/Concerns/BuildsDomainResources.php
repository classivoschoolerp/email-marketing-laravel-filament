<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\DomainStatus;
use App\Jobs\Deliverability\CheckBimiJob;
use App\Models\SendingDomain;
use App\Models\TrackingDomain;
use App\Services\Deliverability\BimiVerifier;
use App\Services\Domains\SendingDomainRecords;
use App\Services\Domains\SendingDomainVerifier;
use App\Services\Domains\TrackingDomainVerifier;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Tables;

/**
 * Shared pieces of the sending/tracking domain resources (customer §2.9.7/
 * §2.9.8 + admin §3.13.5/§3.13.6, WP-15): the DNS-records infolist with
 * copy-to-clipboard entries (F-2.9.7-002/005, F-2.9.8-002), per-record
 * pass/fail display (F-2.9.7-003), status badge column (F-2.9.7-006) and the
 * one-click verify actions. Verification of a SINGLE domain is a handful of
 * DNS lookups and runs inline; the bulk verify-all path is queued
 * (VerifyDomainsJob) / cron (sending-domains:verify, tracking-domains:verify).
 *
 * WP-46 adds the BIMI half (F-2.9.7-009): the logo/VMC inputs, the generated
 * BIMI TXT record, the stored per-check assessment, and a verify action that
 * resolves the BIMI record inline while the logo/certificate FETCH stays in
 * the queued CheckBimiJob — a web request must never block on a third-party
 * asset host.
 */
trait BuildsDomainResources
{
    public static function sendingDomainInfolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('infra.domains.overview'))
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label(__('infra.domains.domain')),
                    Infolists\Components\TextEntry::make('status')
                        ->label(__('infra.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (DomainStatus $state): string => $state->label())
                        ->color(fn (DomainStatus $state): string => $state->color()),
                    Infolists\Components\TextEntry::make('last_checked_at')
                        ->label(__('infra.domains.last_checked'))
                        ->dateTime()
                        ->placeholder(__('sending.labels.never')),
                    Infolists\Components\IconEntry::make('dkim_verified')
                        ->label(__('infra.domains.dkim_verified'))
                        ->boolean(),
                    Infolists\Components\IconEntry::make('spf_verified')
                        ->label(__('infra.domains.spf_verified'))
                        ->boolean(),
                    Infolists\Components\IconEntry::make('dmarc_verified')
                        ->label(__('infra.domains.dmarc_verified'))
                        ->boolean(),
                    Infolists\Components\IconEntry::make('mx_verified')
                        ->label(__('infra.domains.mx_verified'))
                        ->boolean(),
                    // F-2.9.7-009 — advisory: never part of the badge gate.
                    Infolists\Components\IconEntry::make('bimi_verified')
                        ->label(__('deliverability.bimi.verified'))
                        ->visible(fn (): bool => static::bimiAvailable())
                        ->boolean(),
                ]),

            // F-2.9.7-002/005 — required DNS records, copy-to-clipboard
            Infolists\Components\Section::make(__('infra.domains.records'))
                ->description(__('infra.domains.records_description'))
                ->schema([
                    Infolists\Components\TextEntry::make('dkim_record_name')
                        ->label(__('infra.domains.dkim_record', ['type' => 'TXT']))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['dkim']['name'])
                        ->copyable(),
                    Infolists\Components\TextEntry::make('dkim_record_value')
                        ->label(__('infra.domains.record_value'))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['dkim']['value'])
                        ->copyable(),
                    Infolists\Components\TextEntry::make('spf_record_name')
                        ->label(__('infra.domains.spf_record', ['type' => 'TXT']))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['spf']['name'])
                        ->copyable(),
                    Infolists\Components\TextEntry::make('spf_record_value')
                        ->label(__('infra.domains.record_value'))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['spf']['value'])
                        ->copyable(),
                    Infolists\Components\TextEntry::make('dmarc_record_name')
                        ->label(__('infra.domains.dmarc_record', ['type' => 'TXT']))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['dmarc']['name'])
                        ->copyable(),
                    Infolists\Components\TextEntry::make('dmarc_record_value')
                        ->label(__('infra.domains.record_value'))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['dmarc']['value'])
                        ->copyable(),
                    Infolists\Components\TextEntry::make('mx_record')
                        ->label(__('infra.domains.mx_record'))
                        ->helperText(__('infra.domains.mx_optional'))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['mx']['value'])
                        ->copyable(),
                ]),

            // F-2.9.7-009 — BIMI record sheet + the last per-check assessment
            Infolists\Components\Section::make(__('deliverability.bimi.section'))
                ->description(__('deliverability.bimi.description'))
                ->visible(fn (): bool => static::bimiAvailable())
                ->schema([
                    Infolists\Components\TextEntry::make('bimi_record_name')
                        ->label(__('deliverability.bimi.record', ['type' => 'TXT']))
                        ->state(fn (SendingDomain $record): string => app(SendingDomainRecords::class)->for($record)['bimi']['name'])
                        ->copyable(),
                    Infolists\Components\TextEntry::make('bimi_record_value')
                        ->label(__('infra.domains.record_value'))
                        ->state(fn (SendingDomain $record): ?string => app(SendingDomainRecords::class)->for($record)['bimi']['value'] ?: null)
                        ->placeholder(__('deliverability.bimi.record_placeholder'))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('bimi_checked_at')
                        ->label(__('deliverability.bimi.checked_at'))
                        ->dateTime()
                        ->placeholder(__('sending.labels.never')),
                    Infolists\Components\TextEntry::make('bimi_findings')
                        ->label(__('deliverability.bimi.findings'))
                        ->state(fn (SendingDomain $record): array => static::bimiFindingLines($record))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder(__('deliverability.bimi.no_findings'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Human-readable lines for the stored BIMI assessment (F-2.9.7-009).
     *
     * @return list<string>
     */
    public static function bimiFindingLines(SendingDomain $record): array
    {
        $findings = $record->bimi_findings;

        if (! is_array($findings)) {
            return [];
        }

        $lines = [];

        foreach ($findings as $finding) {
            if (! is_array($finding) || ! isset($finding['status'], $finding['message'])) {
                continue;
            }

            $lines[] = __('deliverability.bimi.status.'.$finding['status']).' — '.$finding['message'];
        }

        return $lines;
    }

    /** BIMI ships with the optional deliverability module. */
    public static function bimiAvailable(): bool
    {
        return class_exists(BimiVerifier::class);
    }

    /**
     * BIMI logo + VMC URL inputs (F-2.9.7-009). HTTPS is enforced at the form
     * boundary because a BIMI asset served over plain http is simply ignored
     * by every receiver — catching it here beats reporting it as a finding.
     *
     * @return list<Forms\Components\Component>
     */
    public static function bimiFormFields(): array
    {
        return [
            Forms\Components\TextInput::make('bimi_logo_url')
                ->label(__('deliverability.bimi.logo_url'))
                ->helperText(__('deliverability.bimi.logo_url_help'))
                ->url()
                ->regex('#^https://#i')
                ->maxLength(512),
            Forms\Components\TextInput::make('bimi_vmc_url')
                ->label(__('deliverability.bimi.vmc_url'))
                ->helperText(__('deliverability.bimi.vmc_url_help'))
                ->url()
                ->regex('#^https://#i')
                ->maxLength(512),
        ];
    }

    /** One-click "Verify DNS" for a sending domain (F-2.9.7-003 + F-2.9.7-009). */
    public static function verifySendingDomainAction(string $actionClass): mixed
    {
        return $actionClass::make('verify')
            ->label(__('infra.actions.verify'))
            ->icon('heroicon-o-shield-check')
            ->action(function (SendingDomain $record): void {
                $record = app(SendingDomainVerifier::class)->verify($record);
                static::refreshBimiForDomain($record);

                $record->isVerified()
                    ? Notification::make()->title(__('infra.notifications.domain_verified'))->success()->send()
                    : Notification::make()->title(__('infra.notifications.domain_failed'))->danger()->send();
            });
    }

    /** Queue a full BIMI check (logo + certificate fetch) — F-2.9.7-009. */
    public static function checkBimiAction(string $actionClass): mixed
    {
        return $actionClass::make('check_bimi')
            ->label(__('deliverability.bimi.actions.check'))
            ->icon('heroicon-o-sparkles')
            // BIMI ships with the optional deliverability module.
            ->visible(fn (): bool => static::bimiAvailable())
            ->action(function (SendingDomain $record): void {
                CheckBimiJob::dispatch((int) $record->id);

                Notification::make()
                    ->title(__('deliverability.bimi.notifications.queued', ['domain' => $record->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * The DNS half of the BIMI check, run inline next to the other record
     * lookups (F-2.9.7-009). Asset fetching stays in CheckBimiJob — a web
     * request must never block on a third-party logo host.
     */
    public static function refreshBimiForDomain(SendingDomain $record): void
    {
        if (! static::bimiAvailable()) {
            return;
        }

        app(BimiVerifier::class)->verify($record, fetchAssets: false);
    }

    /** One-click CNAME verify for a tracking domain (F-2.9.8-003). */
    public static function verifyTrackingDomainAction(string $actionClass): mixed
    {
        return $actionClass::make('verify')
            ->label(__('infra.actions.verify'))
            ->icon('heroicon-o-shield-check')
            ->action(function (TrackingDomain $record): void {
                $record = app(TrackingDomainVerifier::class)->verify($record);

                $record->isVerified()
                    ? Notification::make()->title(__('infra.notifications.domain_verified'))->success()->send()
                    : Notification::make()->title(__('infra.notifications.domain_failed'))->danger()->send();
            });
    }

    /** @return list<Tables\Columns\Column> */
    public static function sendingDomainColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('infra.domains.domain'))
                ->searchable()
                ->sortable(),
            Tables\Columns\IconColumn::make('dkim_verified')
                ->label(__('infra.domains.dkim'))
                ->boolean(),
            Tables\Columns\IconColumn::make('spf_verified')
                ->label(__('infra.domains.spf'))
                ->boolean(),
            Tables\Columns\IconColumn::make('dmarc_verified')
                ->label(__('infra.domains.dmarc'))
                ->boolean(),
            Tables\Columns\TextColumn::make('status')
                ->label(__('infra.fields.status'))
                ->badge()
                ->formatStateUsing(fn (DomainStatus $state): string => $state->label())
                ->color(fn (DomainStatus $state): string => $state->color()),
            Tables\Columns\TextColumn::make('last_checked_at')
                ->label(__('infra.domains.last_checked'))
                ->since()
                ->placeholder(__('sending.labels.never')),
        ];
    }

    /** @return list<Tables\Columns\Column> */
    public static function trackingDomainColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('infra.domains.domain'))
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('cname_target')
                ->label(__('infra.domains.cname_target'))
                ->state(fn (): string => TrackingDomain::cnameTarget())
                ->copyable(),
            Tables\Columns\IconColumn::make('verified')
                ->label(__('infra.domains.verified'))
                ->boolean(),
            Tables\Columns\TextColumn::make('ssl_status')
                ->label(__('infra.domains.ssl_status'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => __('infra.ssl_status.'.$state))
                ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
            Tables\Columns\TextColumn::make('status')
                ->label(__('infra.fields.status'))
                ->badge()
                ->formatStateUsing(fn (DomainStatus $state): string => $state->label())
                ->color(fn (DomainStatus $state): string => $state->color()),
            Tables\Columns\TextColumn::make('last_checked_at')
                ->label(__('infra.domains.last_checked'))
                ->since()
                ->placeholder(__('sending.labels.never')),
        ];
    }
}
