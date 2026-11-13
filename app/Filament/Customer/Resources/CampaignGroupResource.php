<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Filament\Customer\Resources\CampaignGroupResource\Pages;
use App\Models\CampaignGroup;
use App\Models\Customer;
use App\Services\Reports\CampaignComparisonService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Campaign groups CRUD (spec §2.3.10 F-2.3.10-001) — a modal-driven manage
 * page; assignment happens in the campaign wizard, filtering on the index.
 */
class CampaignGroupResource extends Resource
{
    protected static ?string $model = CampaignGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $slug = 'campaign-groups';

    protected static ?int $navigationSort = 21;

    public static function getModelLabel(): string
    {
        return __('campaigns.groups.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('campaigns.groups.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('campaigns.groups.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('campaigns.resource.navigation_group');
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
                ->label(__('campaigns.groups.name'))
                ->required()
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('campaigns.groups.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('campaigns_count')
                    ->label(__('campaigns.groups.campaigns_count'))
                    ->counts('campaigns'),
            ])
            ->actions([
                // WP-35 §2.3.10 F-2.3.10-003 — group-level aggregate report.
                // Rates are recomputed from the TOTALS by
                // CampaignComparisonService, never averaged from per-campaign
                // rates, so a 10-recipient send cannot outweigh a 100k one.
                Tables\Actions\Action::make('aggregate')
                    ->label(__('campaigns.groups.aggregate'))
                    ->icon('heroicon-m-chart-bar')
                    ->modalHeading(fn (CampaignGroup $record): string => __('campaigns.groups.aggregate_for', [
                        'name' => $record->name,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalContent(fn (CampaignGroup $record) => view(
                        'filament.customer.campaigns.group-aggregate',
                        ['aggregate' => app(CampaignComparisonService::class)->groupAggregate($record)],
                    )),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                // D-28: filter to rows the actor may delete.
                Tables\Actions\DeleteBulkAction::make()
                    ->using(fn (Collection $records) => $records
                        ->filter(fn (CampaignGroup $record): bool => auth('customer')->user()?->can('delete', $record) ?? false)
                        ->each(fn (CampaignGroup $record) => $record->delete())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCampaignGroups::route('/'),
        ];
    }
}
