<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\FormResource\Pages;

use App\Filament\Customer\Resources\FormResource;
use App\Models\Form as FormModel;
use App\Services\Forms\FormEmbedGenerator;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Embed codes for a form (spec F-2.7-011): the JS snippet, the iframe and
 * the raw HTML, side by side with a live preview of the rendered form.
 */
class FormEmbedCode extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FormResource::class;

    protected static string $view = 'filament.customer.resources.form-resource.pages.form-embed-code';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getRecord(): FormModel
    {
        /** @var FormModel $record */
        $record = $this->record;

        return $record;
    }

    public function getTitle(): string
    {
        return __('forms.embed.title').' — '.$this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label(__('forms.embed.open_preview'))
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => route('forms.public.show', ['uid' => $this->getRecord()->uid]))
                ->openUrlInNewTab(),

            Action::make('edit')
                ->label(__('forms.actions.settings'))
                ->icon('heroicon-m-cog-6-tooth')
                ->color('gray')
                ->url(fn (): string => FormResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    public function scriptSnippet(): string
    {
        return app(FormEmbedGenerator::class)->script($this->getRecord());
    }

    public function iframeSnippet(): string
    {
        return app(FormEmbedGenerator::class)->iframe($this->getRecord());
    }

    public function htmlSnippet(): string
    {
        return app(FormEmbedGenerator::class)->html($this->getRecord());
    }

    public function previewUrl(): string
    {
        return route('forms.public.show', ['uid' => $this->getRecord()->uid]);
    }
}
