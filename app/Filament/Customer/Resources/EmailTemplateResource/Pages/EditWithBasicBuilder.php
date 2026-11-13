<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailTemplateResource\Pages;

use App\Filament\Customer\Concerns\EditsBasicBlocks;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\EmailTemplateResource\Concerns\HasTemplateRevisions;
use App\Models\EmailTemplate;
use App\Services\Templates\TemplateContentSaver;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

/**
 * Basic / simple built-in builder for templates (spec §2.5.3 F-2.5.3-001).
 *
 * Server-rendered block list + Alpine-driven native drag-and-drop; no new JS
 * dependency and no bundled editor. Saves through the ONE template write path
 * (TemplateContentSaver) so the unsubscribe check, hide-on-mobile guard,
 * revisions and thumbnail refresh all apply exactly as they do for GrapesJS.
 * D-29: InteractsWithRecord, never a model-typed $record property.
 */
class EditWithBasicBuilder extends Page
{
    use EditsBasicBlocks;
    use HasTemplateRevisions;
    use InteractsWithRecord;

    protected static string $resource = EmailTemplateResource::class;

    protected static string $view = 'filament.customer.templates.basic-builder';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);

        $this->loadBlocks($this->getTemplate()->content_json);
    }

    public function getTemplate(): EmailTemplate
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return __('templates.basic_builder.title', ['name' => $this->getTemplate()->name]);
    }

    protected function documentTitle(): string
    {
        return (string) $this->getTemplate()->name;
    }

    protected function persistBlocks(string $html, string $json): void
    {
        try {
            app(TemplateContentSaver::class)->save($this->getTemplate(), [
                'content_html' => $html,
                'content_json' => $json,
            ]);
        } catch (ValidationException $e) {
            // Unsubscribe check in block mode (F-2.5.5-006) — the edit stands
            // in the panel, the write does not, and the user is told why.
            Notification::make()->danger()
                ->title(collect($e->errors())->flatten()->first())
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('templates.actions.back_to_index'))
                ->icon('heroicon-m-arrow-left')
                ->url(EmailTemplateResource::getUrl('index'))
                ->color('gray'),
            Actions\Action::make('settings')
                ->label(__('templates.actions.template_settings'))
                ->icon('heroicon-m-adjustments-horizontal')
                ->url(fn (): string => EmailTemplateResource::getUrl('edit', ['record' => $this->record])
                    .'?settings=1'),
            // WP-46 §2.15.8 F-2.15.8-006 / F-2.15.8-009.
            $this->revisionsAction(),
        ];
    }
}
