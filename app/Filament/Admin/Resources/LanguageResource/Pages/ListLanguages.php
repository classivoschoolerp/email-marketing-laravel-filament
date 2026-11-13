<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LanguageResource\Pages;

use App\Filament\Admin\Resources\LanguageResource;
use App\Services\I18n\Locales;
use Filament\Resources\Pages\ListRecords;

class ListLanguages extends ListRecords
{
    protected static string $resource = LanguageResource::class;

    public function mount(): void
    {
        parent::mount();

        // Keep the grid's default badge honest even when the default was
        // changed on the CommonSettings page (source of truth, WP-20).
        Locales::syncDefaultFlag();
    }
}
