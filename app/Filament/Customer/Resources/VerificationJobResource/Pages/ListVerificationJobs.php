<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\VerificationJobResource\Pages;

use App\Enums\VerificationStatus;
use App\Filament\Customer\Resources\VerificationJobResource;
use App\Jobs\Verification\VerifyBatchJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Models\VerificationJob;
use App\Services\Preferences\CustomerPreferences;
use App\Services\Verification\Credentials;
use App\Services\Verification\ProviderRegistry;
use App\Services\Verification\SingleAddressVerifier;
use App\Services\Verification\VerificationCredits;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * §2.9.12 verification index (WP-16): new queued run (whole list /
 * segment), single-address real-time check, per-customer provider
 * credentials, credits display in the subheading (F-2.9.12-008/011).
 */
class ListVerificationJobs extends ListRecords
{
    protected static string $resource = VerificationJobResource::class;

    public function getSubheading(): ?string
    {
        $customer = $this->customer();

        if ($customer === null) {
            return null;
        }

        $remaining = app(VerificationCredits::class)->remaining($customer);

        return $remaining === null
            ? __('verification.credits.unlimited')
            : __('verification.credits.remaining', ['count' => $remaining]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('newVerification')
                ->label(__('verification.actions.new'))
                ->icon('heroicon-o-plus')
                ->authorize('create', VerificationJob::class)
                ->form([
                    Forms\Components\Select::make('target')
                        ->label(__('verification.form.target'))
                        ->options([
                            VerificationJob::TARGET_LIST => __('verification.target.list'),
                            VerificationJob::TARGET_SEGMENT => __('verification.target.segment'),
                        ])
                        ->default(VerificationJob::TARGET_LIST)
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('list_id')
                        ->label(__('verification.form.list'))
                        ->options(fn (): array => EmailList::query()->pluck('name', 'id')->all())
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('segment_id')
                        ->label(__('verification.form.segment'))
                        ->options(fn (Get $get): array => $get('list_id') === null ? [] : ListSegment::query()
                            ->where('list_id', (int) $get('list_id'))
                            ->pluck('name', 'id')
                            ->all())
                        ->visible(fn (Get $get): bool => $get('target') === VerificationJob::TARGET_SEGMENT)
                        ->required(fn (Get $get): bool => $get('target') === VerificationJob::TARGET_SEGMENT),
                    Forms\Components\Select::make('provider')
                        ->label(__('verification.form.provider'))
                        ->options(fn (): array => app(ProviderRegistry::class)->options())
                        ->placeholder(__('verification.form.provider_default')),
                    Forms\Components\Select::make('auto_action')
                        ->label(__('verification.form.auto_action'))
                        ->options([
                            VerificationJob::ACTION_TAG => __('verification.auto_action.tag'),
                            VerificationJob::ACTION_DELETE => __('verification.auto_action.delete'),
                            VerificationJob::ACTION_UNSUBSCRIBE => __('verification.auto_action.unsubscribe'),
                            VerificationJob::ACTION_BLACKLIST => __('verification.auto_action.blacklist'),
                        ])
                        ->default(VerificationJob::ACTION_TAG)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $customer = $this->customer();

                    if ($customer === null) {
                        return;
                    }

                    $providerKey = ($data['provider'] ?? '') !== '' && $data['provider'] !== null
                        ? (string) $data['provider']
                        : app(Credentials::class)->providerKeyFor($customer);

                    $job = VerificationJob::create([
                        'customer_id' => $customer->id,
                        'list_id' => (int) $data['list_id'],
                        'segment_id' => $data['target'] === VerificationJob::TARGET_SEGMENT
                            ? (int) $data['segment_id']
                            : null,
                        'target' => (string) $data['target'],
                        'provider' => $providerKey,
                        'auto_action' => (string) $data['auto_action'],
                        'status' => VerificationJob::STATUS_PENDING,
                    ]);

                    VerifyBatchJob::dispatch((int) $job->id);

                    Notification::make()
                        ->title(__('verification.actions.queued'))
                        ->success()
                        ->send();
                }),

            Actions\Action::make('verifySingle')
                ->label(__('verification.actions.verify_single'))
                ->icon('heroicon-o-magnifying-glass')
                ->authorize('create', VerificationJob::class)
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label(__('verification.form.email'))
                        ->email()
                        ->required(),
                    Forms\Components\Select::make('provider')
                        ->label(__('verification.form.provider'))
                        ->options(fn (): array => app(ProviderRegistry::class)->options())
                        ->placeholder(__('verification.form.provider_default')),
                ])
                ->action(function (array $data): void {
                    $customer = $this->customer();

                    if ($customer === null) {
                        return;
                    }

                    $row = app(SingleAddressVerifier::class)->verify(
                        $customer,
                        (string) $data['email'],
                        ($data['provider'] ?? '') !== '' ? (string) $data['provider'] : null,
                    );

                    /** @var VerificationStatus $status */
                    $status = $row->status;

                    Notification::make()
                        ->title(__('verification.single_result.title'))
                        ->body(__('verification.single_result.body', [
                            'email' => $row->email,
                            'status' => $status->label(),
                            'reason' => (string) $row->reason,
                        ]))
                        ->status($status === VerificationStatus::Deliverable ? 'success' : 'warning')
                        ->send();
                }),

            Actions\Action::make('providerSettings')
                ->label(__('verification.actions.settings'))
                ->icon('heroicon-o-cog-6-tooth')
                ->form(function (): array {
                    $customer = $this->customer();
                    $registry = app(ProviderRegistry::class);
                    $prefs = app(CustomerPreferences::class);

                    $fields = [
                        Forms\Components\Select::make('provider')
                            ->label(__('verification.settings.provider'))
                            ->options($registry->options())
                            ->default($customer !== null
                                ? app(Credentials::class)->providerKeyFor($customer)
                                : null)
                            ->required(),
                    ];

                    foreach ($registry->keys() as $key) {
                        if (! $registry->make($key)->requiresApiKey()) {
                            continue;
                        }

                        $fields[] = Forms\Components\TextInput::make('api_key_'.str_replace('-', '_', $key))
                            ->label(__('verification.settings.api_key', ['provider' => $registry->make($key)->name()]))
                            ->helperText(__('verification.settings.api_key_help'))
                            ->default($customer !== null
                                ? (string) $prefs->get($customer, Credentials::PREF_API_KEY_PREFIX.$key, '')
                                : '');
                    }

                    return $fields;
                })
                ->action(function (array $data): void {
                    $customer = $this->customer();

                    if ($customer === null) {
                        return;
                    }

                    $prefs = app(CustomerPreferences::class);
                    $registry = app(ProviderRegistry::class);

                    $prefs->set($customer, Credentials::PREF_PROVIDER, (string) $data['provider']);

                    foreach ($registry->keys() as $key) {
                        $field = 'api_key_'.str_replace('-', '_', $key);

                        if (array_key_exists($field, $data)) {
                            $prefs->set($customer, Credentials::PREF_API_KEY_PREFIX.$key, (string) $data[$field]);
                        }
                    }

                    Notification::make()
                        ->title(__('verification.actions.saved'))
                        ->success()
                        ->send();
                }),
        ];
    }

    private function customer(): ?Customer
    {
        $user = Filament::auth()->user();

        return $user instanceof Customer ? $user : null;
    }
}
