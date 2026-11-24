<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Pages;

use App\Enums\DomainStatus;
use App\Enums\LandingPageStatus;
use App\Filament\Customer\Resources\LandingPageResource;
use App\Filament\Customer\Resources\LandingPageResource\Concerns\HasLandingRevisions;
use App\Models\LandingPage;
use App\Models\TrackingDomain;
use App\Services\Domains\TrackingDomainVerifier;
use App\Services\Landing\LandingPageUrl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Landing-page settings (spec §2.6 F-2.6-006/007/010/011/012).
 *
 * The publish actions live here as well as on the index so the customer can
 * go live from wherever they are; scheduling (F-2.6-012) is the
 * `scheduled_at` field plus the Scheduled status, and the DNS re-check
 * (F-2.6-006) delegates to the §2.9.8 {@see TrackingDomainVerifier} rather
 * than re-implementing a CNAME check.
 */
class EditLandingPage extends EditRecord
{
    use HasLandingRevisions;

    protected static string $resource = LandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('build')
                ->label(__('landing.actions.build'))
                ->icon('heroicon-m-squares-2x2')
                ->color('gray')
                ->url(fn (): string => LandingPageResource::getUrl('build', ['record' => $this->getRecord()])),

            Actions\Action::make('open')
                ->label(__('landing.actions.open'))
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => app(LandingPageUrl::class)->url($this->page()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->page()->isLive()),

            Actions\Action::make('verify_domain')
                ->label(__('landing.actions.verify_domain'))
                ->icon('heroicon-m-shield-check')
                ->color('gray')
                ->visible(fn (): bool => $this->page()->tracking_domain_id !== null)
                ->action(fn () => $this->verifyDomain()),

            Actions\Action::make('publish')
                ->label(__('landing.actions.publish'))
                ->icon('heroicon-m-rocket-launch')
                ->visible(fn (): bool => $this->page()->status !== LandingPageStatus::Published)
                ->action(function (): void {
                    ListLandingPages::publish($this->page());
                    $this->fillForm();
                }),

            Actions\Action::make('unpublish')
                ->label(__('landing.actions.unpublish'))
                ->icon('heroicon-m-eye-slash')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->page()->status === LandingPageStatus::Published)
                ->action(function (): void {
                    ListLandingPages::unpublish($this->page());
                    $this->fillForm();
                }),

            $this->revisionsAction(),

            Actions\DeleteAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = LandingPageResource::normalize($data, $this->page());

        // Scheduling is a state, not just a date: setting a future time on a
        // draft is what makes it a scheduled page (F-2.6-012).
        if (! empty($data['scheduled_at'])
            && ($data['status'] ?? null) === LandingPageStatus::Draft->value) {
            $data['status'] = LandingPageStatus::Scheduled->value;
        }

        if (($data['status'] ?? null) === LandingPageStatus::Published->value
            && $this->page()->published_at === null) {
            $data['published_at'] = now();
        }

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }

    private function page(): LandingPage
    {
        /** @var LandingPage $record */
        $record = $this->getRecord();

        return $record;
    }

    /**
     * Re-run the §2.9.8 CNAME check on the assigned domain. SSL is NOT
     * touched here: nothing in this product issues a certificate, and
     * flipping the column would be a lie the customer would act on.
     */
    private function verifyDomain(): void
    {
        $domain = TrackingDomain::query()->find($this->page()->tracking_domain_id);

        if ($domain === null) {
            Notification::make()->danger()->title(__('landing.errors.no_domain'))->send();

            return;
        }

        $domain = app(TrackingDomainVerifier::class)->verify($domain);

        if ($domain->status === DomainStatus::Verified) {
            Notification::make()->success()->title(__('landing.notifications.domain_verified'))->send();

            return;
        }

        Notification::make()->warning()
            ->title(__('landing.notifications.domain_failed', ['target' => TrackingDomain::cnameTarget()]))
            ->send();
    }
}
