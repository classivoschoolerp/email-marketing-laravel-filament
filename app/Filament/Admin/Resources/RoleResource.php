<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\RoleScope;
use App\Filament\Admin\Resources\RoleResource\Pages;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

/**
 * Admin roles UI (spec §0.3 F-0.3-006/010/011): operator-defined global
 * delegation roles composed from the admin permission set, plus the locked
 * system roles (read-only per RolePolicy/D-18). Soft disable/enable
 * preserves assignments (F-0.3-010). Customer-scoped roles are tenant data
 * and stay out of this admin module.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static ?string $slug = 'roles';

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('admin.roles.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.roles.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.roles.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.access');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('guard_name', 'admin')
            ->whereNull('customer_id');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.roles.form.general'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('admin.roles.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Placeholder::make('scope_display')
                        ->label(__('admin.roles.fields.scope'))
                        ->content(fn (?Role $record): string => ($record?->scope ?? RoleScope::Global)->label()),
                ]),

            Forms\Components\Section::make(__('admin.roles.form.permissions'))
                ->description(__('admin.roles.help.permissions'))
                ->schema([
                    Forms\Components\CheckboxList::make('permission_names')
                        ->label(__('admin.roles.fields.permissions'))
                        ->options(fn (): array => Permission::query()
                            ->where('guard_name', 'admin')
                            ->orderBy('name')
                            ->pluck('name', 'name')
                            ->all())
                        ->columns(3)
                        ->dehydrated(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.roles.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('scope')
                    ->label(__('admin.roles.fields.scope'))
                    ->badge()
                    ->formatStateUsing(fn (RoleScope $state): string => $state->label())
                    ->color(fn (RoleScope $state): string => $state === RoleScope::System ? 'warning' : 'info'),
                Tables\Columns\IconColumn::make('locked')
                    ->label(__('admin.roles.fields.locked'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label(__('admin.roles.fields.permissions_count'))
                    ->counts('permissions'),
                Tables\Columns\TextColumn::make('disabled_at')
                    ->label(__('admin.roles.fields.disabled_at'))
                    ->dateTime()
                    ->placeholder(__('admin.roles.enabled_placeholder')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('disable')
                    ->label(__('admin.roles.actions.disable'))
                    ->icon('heroicon-m-pause-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Role $record): bool => ! $record->isDisabled()
                        && auth('admin')->user()->can('update', $record))
                    ->action(function (Role $record): void {
                        $record->disable();

                        Notification::make()->success()
                            ->title(__('admin.roles.notifications.disabled'))
                            ->send();
                    }),

                Tables\Actions\Action::make('enable')
                    ->label(__('admin.roles.actions.enable'))
                    ->icon('heroicon-m-play-circle')
                    ->color('success')
                    ->visible(fn (Role $record): bool => $record->isDisabled()
                        && auth('admin')->user()->can('update', $record))
                    ->action(function (Role $record): void {
                        $record->enable();

                        Notification::make()->success()
                            ->title(__('admin.roles.notifications.enabled'))
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
