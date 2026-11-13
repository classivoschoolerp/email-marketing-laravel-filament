<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\DeliveryServerResource;
use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\SendingDomainResource;
use App\Models\Customer;
use App\Services\Analytics\GettingStartedChecklist;
use Filament\Widgets\Widget;

/**
 * §2.1 F-2.1-007 — the getting-started checklist (WP-40).
 *
 * Sits at the very top of the dashboard while an account still has work to
 * do, and disappears the moment every step is complete. State is derived
 * live by {@see GettingStartedChecklist}, never stored.
 */
class GettingStartedWidget extends Widget
{
    protected static string $view = 'filament.customer.widgets.getting-started';

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $customer = auth('customer')->user();

        if (! $customer instanceof Customer) {
            return false;
        }

        return ! app(GettingStartedChecklist::class)->for($customer)['finished'];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        return app(GettingStartedChecklist::class)->for($customer) + [
            'urls' => self::urls(),
        ];
    }

    /**
     * Where each step takes the customer. Built from the resources' own URL
     * helpers so a slug change can never leave a dead link here.
     *
     * @return array<string, string>
     */
    public static function urls(): array
    {
        return [
            'create_list' => EmailListResource::getUrl('create', panel: 'customer'),
            'add_subscribers' => EmailListResource::getUrl('index', panel: 'customer'),
            'verify_domain' => SendingDomainResource::getUrl('index', panel: 'customer'),
            'configure_server' => DeliveryServerResource::getUrl('index', panel: 'customer'),
            'create_campaign' => CampaignResource::getUrl('create', panel: 'customer'),
            'send_campaign' => CampaignResource::getUrl('index', panel: 'customer'),
        ];
    }
}
