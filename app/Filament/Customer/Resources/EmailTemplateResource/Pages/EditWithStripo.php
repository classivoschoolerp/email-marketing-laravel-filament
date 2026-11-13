<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailTemplateResource\Pages;

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Templates\Builders\StripoDriver;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * §2.5.3 F-2.5.3-003 embedded Stripo editor page (WP-26).
 *
 * Renders the two containers Stripo's plugin requires
 * (stripoSettingsContainer / stripoPreviewContainer) and boots them with the
 * server-issued auth token from StripoDriver. Without credentials the driver
 * is unavailable and this page 403s — there is no half-working editor.
 *
 * The compiled HTML is posted back through the existing builder autosave
 * route, so a Stripo template revisions and renders exactly like any other
 * (D-29: InteractsWithRecord, never a model-typed $record).
 */
class EditWithStripo extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmailTemplateResource::class;

    protected static string $view = 'filament.customer.templates.stripo';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);
        abort_unless(app(StripoDriver::class)->isAvailable(), 403, __('templates.stripo.unavailable'));
    }

    public function getTemplate(): EmailTemplate
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return __('templates.stripo.title', ['name' => $this->getTemplate()->name]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth('customer')->user();

        return [
            'stripo' => app(StripoDriver::class)->editorConfig(
                $this->getTemplate(),
                $user instanceof Customer ? $user : null,
            ),
            'csrfToken' => csrf_token(),
        ];
    }

    /** @return array<int, Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('templates.stripo.back_to_list'))
                ->url(EmailTemplateResource::getUrl('index', panel: 'customer'))
                ->color('gray'),
        ];
    }
}
