<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\AutomationNodeType;
use App\Filament\Customer\Resources\AutomationResource\Pages;
use App\Models\Automation;
use App\Models\Customer;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Automations module (spec §2.4 — WP-21). Index (§2.4.1), builder canvas
 * (§2.4.2) and report (§2.4.3) pages. Tenancy: global scope + explicit
 * customer_id constraint (defense in depth, CampaignResource pattern).
 */
class AutomationResource extends Resource
{
    protected static ?string $model = Automation::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $slug = 'automations';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('automations.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('automations.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('automations.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('automations.resource.plural_label');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('automations.fields.name'))
                ->required()
                ->maxLength(190),

            Forms\Components\Select::make('trigger_type')
                ->label(__('automations.fields.trigger'))
                ->options(AutomationNodeType::optionsFor(AutomationNodeType::triggers()))
                ->required()
                ->visibleOn('create'),

            Forms\Components\Select::make('reentry_mode')
                ->label(__('automations.fields.reentry_mode'))
                ->options([
                    Automation::REENTRY_ONCE => __('automations.reentry.once'),
                    Automation::REENTRY_MULTIPLE => __('automations.reentry.multiple'),
                    Automation::REENTRY_COOLDOWN => __('automations.reentry.cooldown'),
                ])
                ->default(Automation::REENTRY_ONCE)
                ->live()
                ->required(),

            Forms\Components\TextInput::make('reentry_cooldown_minutes')
                ->label(__('automations.fields.reentry_cooldown_minutes'))
                ->numeric()
                ->minValue(1)
                ->required(fn (Forms\Get $get): bool => $get('reentry_mode') === Automation::REENTRY_COOLDOWN)
                ->visible(fn (Forms\Get $get): bool => $get('reentry_mode') === Automation::REENTRY_COOLDOWN),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomations::route('/'),
            'create' => Pages\CreateAutomation::route('/create'),
            'edit' => Pages\EditAutomation::route('/{record}/edit'),
            'builder' => Pages\BuildAutomation::route('/{record}/builder'),
            'report' => Pages\AutomationReport::route('/{record}/report'),
        ];
    }
}
