<?php

declare(strict_types=1);

namespace App\Filament\Customer\Pages;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Customer;
use App\Services\Notifications\NotificationPreferences;
use App\Support\Billing\Monetization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * §2.15.3 notification preferences (WP-39): one toggle per event × channel.
 *
 * The toggles are not decoration — CustomerNotifier and every wired sender
 * ask NotificationPreferences before delivering, so switching one off
 * actually suppresses the email and the bell entry.
 *
 * Billing events are hidden when the operator has switched monetization off
 * (F-3.25.14-001); there is no billing to be notified about.
 */
class NotificationPreferencesPage extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static string $view = 'filament.customer.pages.notification-preferences';

    protected static ?string $slug = 'notification-preferences';

    protected static ?int $navigationSort = 94;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('auth-module.profile.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('notifications.preferences.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('notifications.preferences.title');
    }

    public function mount(): void
    {
        $this->form->fill($this->flatten(app(NotificationPreferences::class)->matrix($this->customer())));
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema($this->schema());
    }

    /** @return array<int, Component> */
    protected function schema(): array
    {
        $sections = [];

        foreach ($this->events() as $event) {
            $toggles = [];

            foreach (NotificationChannel::cases() as $channel) {
                $toggles[] = Toggle::make($this->key($event, $channel))
                    ->label($channel->label())
                    ->inline(false);
            }

            $sections[] = Section::make($event->label())
                ->description($event->description())
                ->columns(2)
                ->schema($toggles);
        }

        return $sections;
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $matrix = [];

        foreach ($this->events() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $matrix[$event->value][$channel->value] = (bool) ($state[$this->key($event, $channel)] ?? false);
            }
        }

        app(NotificationPreferences::class)->save($this->customer(), $matrix);

        Notification::make()
            ->success()
            ->title(__('notifications.preferences.saved'))
            ->send();
    }

    /** @return array<int, Action> */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('notifications.preferences.save'))
                ->submit('save'),
        ];
    }

    /** @return list<NotificationEvent> */
    private function events(): array
    {
        $billingVisible = class_exists(Monetization::class) && Monetization::enabled();

        return array_values(array_filter(
            NotificationEvent::cases(),
            static fn (NotificationEvent $event): bool => $billingVisible || ! $event->isBilling(),
        ));
    }

    /**
     * Flat state keys — Filament form paths cannot contain a dot.
     *
     * @param  array<string, array<string, bool>>  $matrix
     * @return array<string, bool>
     */
    private function flatten(array $matrix): array
    {
        $flat = [];

        foreach ($this->events() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $flat[$this->key($event, $channel)] = (bool) ($matrix[$event->value][$channel->value] ?? false);
            }
        }

        return $flat;
    }

    private function key(NotificationEvent $event, NotificationChannel $channel): string
    {
        return $event->value.'__'.$channel->value;
    }

    private function customer(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }
}
