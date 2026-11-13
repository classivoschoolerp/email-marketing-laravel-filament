<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Concerns;

use App\Enums\InfraServerStatus;
use App\Enums\MailboxEncryption;
use App\Enums\MailboxProtocol;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Components\Component;

/**
 * Shared mailbox-connection form pieces for the bounce + FBL server admin
 * resources (spec §2.9.4 F-2.9.4-001..004 / §2.9.5 F-2.9.5-001 "same mailbox
 * config"). The password field never wipes a stored secret when left blank
 * on edit (same convention as the delivery-server driver forms).
 */
trait BuildsMailboxForm
{
    protected static function mailboxConnectionSection(): Component
    {
        return Forms\Components\Section::make(__('infra.sections.mailbox'))
            ->columns(2)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('infra.fields.name'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('customer_id')
                    ->label(__('infra.fields.customer'))
                    ->helperText(__('infra.help.mailbox_customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable()
                    ->nullable(),
                Forms\Components\TextInput::make('hostname')
                    ->label(__('infra.fields.hostname'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('port')
                    ->label(__('infra.fields.port'))
                    ->numeric()->integer()->minValue(1)->maxValue(65535)
                    ->default(995)
                    ->required(),
                Forms\Components\Select::make('protocol')
                    ->label(__('infra.fields.protocol'))
                    ->options(MailboxProtocol::options())
                    ->default(MailboxProtocol::Pop3->value)
                    ->required(),
                Forms\Components\Select::make('encryption')
                    ->label(__('infra.fields.encryption'))
                    ->options(MailboxEncryption::options())
                    ->default(MailboxEncryption::Ssl->value)
                    ->required(),
                Forms\Components\TextInput::make('username')
                    ->label(__('infra.fields.username'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->label(__('infra.fields.password'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrateStateUsing(fn ($state) => $state)
                    ->dehydrated(fn ($state): bool => filled($state)),
                Forms\Components\Toggle::make('validate_cert')
                    ->label(__('infra.fields.validate_cert'))
                    ->helperText(__('infra.help.validate_cert'))
                    ->default(true),
            ]);
    }

    protected static function mailboxProcessingSection(): Component
    {
        return Forms\Components\Section::make(__('infra.sections.processing'))
            ->columns(3)
            ->schema([
                Forms\Components\Toggle::make('delete_after_processing')
                    ->label(__('infra.fields.delete_after_processing'))
                    ->helperText(__('infra.help.delete_after_processing'))
                    ->default(true)
                    ->inline(false),
                Forms\Components\TextInput::make('max_per_batch')
                    ->label(__('infra.fields.max_per_batch'))
                    ->helperText(__('infra.help.max_per_batch'))
                    ->numeric()->integer()->minValue(1)
                    ->default(200)
                    ->required(),
                Forms\Components\TextInput::make('search_charset')
                    ->label(__('infra.fields.search_charset'))
                    ->helperText(__('infra.help.search_charset'))
                    ->default('UTF-8')
                    ->maxLength(32)
                    ->required(),
                Forms\Components\Toggle::make('locked')
                    ->label(__('infra.fields.locked'))
                    ->helperText(__('infra.help.locked'))
                    ->inline(false),
                Forms\Components\Select::make('status')
                    ->label(__('infra.fields.status'))
                    ->options(InfraServerStatus::options())
                    ->default(InfraServerStatus::Active->value)
                    ->required(),
            ]);
    }
}
